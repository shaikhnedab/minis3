<?php
// Pure-PHP WebAuthn (passkeys) for the admin panel.
// No dependencies beyond PHP core: openssl (ES256/RS256) and sodium (Ed25519).
// Expects util.php to be loaded for S3Exception.

declare(strict_types=1);

function webauthn_b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function webauthn_b64url_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

function webauthn_challenge(): string
{
    return webauthn_b64url_encode(random_bytes(32));
}

function webauthn_err(string $msg): void
{
    throw new S3Exception('InvalidArgument', $msg, 400);
}

/* ---------------- CBOR (RFC 8949 subset) ---------------- */

function webauthn_cbor_decode(string $data)
{
    $pos = 0;
    $val = webauthn_cbor_item($data, $pos);
    if ($pos !== strlen($data)) {
        webauthn_err('Malformed WebAuthn data.');
    }
    return $val;
}

function webauthn_cbor_item(string $data, int &$pos)
{
    $len = strlen($data);
    if ($pos >= $len) {
        webauthn_err('Malformed WebAuthn data.');
    }
    $ib = ord($data[$pos]);
    $major = ($ib >> 5) & 0x07;
    $info = $ib & 0x1F;
    $pos++;
    $count = webauthn_cbor_len($data, $pos, $info);
    switch ($major) {
        case 0:
            return $count;
        case 1:
            return -1 - $count;
        case 2:
        case 3:
            if ($pos + $count > $len) {
                webauthn_err('Malformed WebAuthn data.');
            }
            $s = substr($data, $pos, $count);
            $pos += $count;
            return $s;
        case 4:
            $arr = [];
            for ($i = 0; $i < $count; $i++) {
                $arr[] = webauthn_cbor_item($data, $pos);
            }
            return $arr;
        case 5:
            $map = [];
            for ($i = 0; $i < $count; $i++) {
                $k = webauthn_cbor_item($data, $pos);
                $v = webauthn_cbor_item($data, $pos);
                $map[$k] = $v;
            }
            return $map;
        case 6:
            return webauthn_cbor_item($data, $pos);
        case 7:
            if ($info === 20) {
                return false;
            }
            if ($info === 21) {
                return true;
            }
            if ($info === 22 || $info === 23) {
                return null;
            }
            webauthn_err('Unsupported CBOR item.');
    }
    webauthn_err('Unsupported CBOR item.');
}

function webauthn_cbor_len(string $data, int &$pos, int $info): int
{
    if ($info < 24) {
        return $info;
    }
    if ($info === 24) {
        $v = ord($data[$pos] ?? '');
        $pos++;
        return $v;
    }
    if ($info === 25) {
        $v = unpack('n', substr($data, $pos, 2))[1];
        $pos += 2;
        return $v;
    }
    if ($info === 26) {
        $v = unpack('N', substr($data, $pos, 4))[1];
        $pos += 4;
        return $v;
    }
    if ($info === 27) {
        $v = unpack('J', substr($data, $pos, 8))[1];
        $pos += 8;
        return $v;
    }
    webauthn_err('Unsupported CBOR length.');
}

/* ---------------- DER / ASN.1 helpers ---------------- */

function webauthn_der_len(int $len): string
{
    if ($len < 128) {
        return chr($len);
    }
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xFF) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

// DER INTEGER for an unsigned big-endian value (WebAuthn bignums).
function webauthn_der_int(string $bignum): string
{
    $bignum = ltrim($bignum, "\x00");
    if ($bignum === '') {
        $bignum = "\x00";
    }
    if ((ord($bignum[0]) & 0x80) !== 0) {
        $bignum = "\x00" . $bignum;
    }
    return "\x02" . webauthn_der_len(strlen($bignum)) . $bignum;
}

// WebAuthn ECDSA signatures are raw R||S; openssl_verify needs DER.
function webauthn_ecdsa_raw_to_der(string $raw): string
{
    if (strlen($raw) % 2 !== 0) {
        return '';
    }
    $half = intdiv(strlen($raw), 2);
    $seq = webauthn_der_int(substr($raw, 0, $half)) . webauthn_der_int(substr($raw, $half));
    return "\x30" . webauthn_der_len(strlen($seq)) . $seq;
}

/* ---------------- COSE key -> public key ---------------- */

// Returns the binary public key: SPKI DER for ES256/RS256, raw 32 bytes for
// Ed25519 (sodium's native format).
function webauthn_cose_pubkey(array $cose, int $alg): string
{
    if ($alg === -8) {
        $x = $cose[-2] ?? '';
        if (!is_string($x) || strlen($x) !== 32) {
            webauthn_err('Invalid Ed25519 passkey key.');
        }
        return $x;
    }
    if ($alg === -7) {
        $x = $cose[-2] ?? '';
        $y = $cose[-3] ?? '';
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            webauthn_err('Invalid ES256 passkey key.');
        }
        // SPKI: SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING { 0x04 X Y } }
        $algId = "\x30\x13" . "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $point = "\x04" . $x . $y;
        $bitString = "\x03" . webauthn_der_len(strlen($point) + 1) . "\x00" . $point;
        return "\x30" . webauthn_der_len(strlen($algId) + strlen($bitString)) . $algId . $bitString;
    }
    if ($alg === -257) {
        $n = $cose[-1] ?? '';
        $e = $cose[-2] ?? '';
        if (!is_string($n) || !is_string($e) || $n === '' || $e === '') {
            webauthn_err('Invalid RS256 passkey key.');
        }
        // SPKI: SEQUENCE { SEQUENCE { OID rsaEncryption, NULL }, BIT STRING { SEQUENCE { INTEGER n, INTEGER e } } }
        $algId = "\x30\x0D" . "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" . "\x05\x00";
        $rsa = webauthn_der_int($n) . webauthn_der_int($e);
        $seq = "\x30" . webauthn_der_len(strlen($rsa)) . $rsa;
        $bitString = "\x03" . webauthn_der_len(strlen($seq) + 1) . "\x00" . $seq;
        return "\x30" . webauthn_der_len(strlen($algId) + strlen($bitString)) . $algId . $bitString;
    }
    webauthn_err('Unsupported passkey algorithm.');
}

/* ---------------- signature verification ---------------- */

function webauthn_pubkey_pem(string $spki): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki)) . "-----END PUBLIC KEY-----\n";
}

function webauthn_verify_sig(int $alg, string $pubkey, string $data, string $signature): bool
{
    if ($alg === -8) {
        return sodium_crypto_sign_verify_detached($signature, $data, $pubkey);
    }
    if ($alg === -7) {
        if (strlen($signature) !== 64) {
            return false;
        }
        return openssl_verify($data, webauthn_ecdsa_raw_to_der($signature), webauthn_pubkey_pem($pubkey), OPENSSL_ALGO_SHA256) === 1;
    }
    if ($alg === -257) {
        return openssl_verify($data, $signature, webauthn_pubkey_pem($pubkey), OPENSSL_ALGO_SHA256) === 1;
    }
    return false;
}

/* ---------------- authenticator data ---------------- */

function webauthn_parse_authdata(string $ad): array
{
    // rpIdHash(32) | flags(1) | signCount(4) | attestedCredentialData? | extensions?
    if (strlen($ad) < 37) {
        webauthn_err('Malformed authenticator data.');
    }
    $rpIdHash = substr($ad, 0, 32);
    $flags = ord($ad[32]);
    $signCount = (int)unpack('N', substr($ad, 33, 4))[1];
    $attested = null;
    if ($flags & 0x40) {
        if (strlen($ad) < 55) {
            webauthn_err('Malformed attested credential data.');
        }
        $aaguid = substr($ad, 37, 16);
        $credIdLen = (int)unpack('n', substr($ad, 53, 2))[1];
        if (strlen($ad) < 55 + $credIdLen) {
            webauthn_err('Malformed attested credential data.');
        }
        $credId = substr($ad, 55, $credIdLen);
        $attested = [
            'aaguid' => $aaguid,
            'credentialId' => $credId,
            'cose' => substr($ad, 55 + $credIdLen),
        ];
    }
    return [
        'rpIdHash' => $rpIdHash,
        'flags' => $flags,
        'signCount' => $signCount,
        'attested' => $attested,
    ];
}

/* ---------------- registration / assertion ---------------- */

function webauthn_check_client_data(array $cd, string $expectedType, string $challenge, string $expectedOrigin): void
{
    if (($cd['type'] ?? '') !== $expectedType) {
        webauthn_err('Passkey request type mismatch.');
    }
    $ch = (string)($cd['challenge'] ?? '');
    if ($ch === '' || !hash_equals(webauthn_b64url_decode($challenge), webauthn_b64url_decode($ch))) {
        webauthn_err('Passkey challenge does not match.');
    }
    if (!hash_equals($expectedOrigin, (string)($cd['origin'] ?? ''))) {
        webauthn_err('Passkey origin does not match.');
    }
    if (!empty($cd['crossOrigin'])) {
        webauthn_err('Cross-origin passkey request rejected.');
    }
}

// Verifies a registration (attestation 'none' / any fmt - the attestation
// statement is not validated, only the authenticator data). Returns
// [credentialId, alg, pubkey, signCount].
function webauthn_verify_registration(string $clientDataJSON, string $attestationObject, string $challenge, string $rpId, string $expectedOrigin): array
{
    $cd = json_decode($clientDataJSON, true);
    if (!is_array($cd)) {
        webauthn_err('Malformed passkey client data.');
    }
    webauthn_check_client_data($cd, 'webauthn.create', $challenge, $expectedOrigin);

    $att = webauthn_cbor_decode($attestationObject);
    if (!is_array($att)) {
        webauthn_err('Malformed passkey attestation.');
    }
    $authData = $att['authData'] ?? '';
    if (!is_string($authData) || $authData === '') {
        webauthn_err('Malformed passkey attestation.');
    }

    $parsed = webauthn_parse_authdata($authData);
    if (!hash_equals(hash('sha256', $rpId, true), $parsed['rpIdHash'])) {
        webauthn_err('Passkey RP ID does not match.');
    }
    if (($parsed['flags'] & 0x01) === 0) {
        webauthn_err('Passkey user presence not verified.');
    }
    if ($parsed['attested'] === null) {
        webauthn_err('Missing attested credential data.');
    }
    $cose = webauthn_cbor_decode($parsed['attested']['cose']);
    if (!is_array($cose)) {
        webauthn_err('Malformed passkey COSE key.');
    }
    $alg = (int)($cose[3] ?? 0);
    if (!in_array($alg, [-7, -257, -8], true)) {
        webauthn_err('Unsupported passkey algorithm.');
    }
    return [
        'credentialId' => $parsed['attested']['credentialId'],
        'alg' => $alg,
        'pubkey' => webauthn_cose_pubkey($cose, $alg),
        'signCount' => $parsed['signCount'],
    ];
}

// Verifies an assertion. Returns the new signCount.
function webauthn_verify_assertion(string $clientDataJSON, string $authenticatorData, string $signature, string $challenge, string $rpId, string $expectedOrigin, string $pubkey, int $alg, int $storedCount): int
{
    $cd = json_decode($clientDataJSON, true);
    if (!is_array($cd)) {
        webauthn_err('Malformed passkey client data.');
    }
    webauthn_check_client_data($cd, 'webauthn.get', $challenge, $expectedOrigin);

    $parsed = webauthn_parse_authdata($authenticatorData);
    if (!hash_equals(hash('sha256', $rpId, true), $parsed['rpIdHash'])) {
        webauthn_err('Passkey RP ID does not match.');
    }
    if (($parsed['flags'] & 0x01) === 0) {
        webauthn_err('Passkey user presence not verified.');
    }
    if (!webauthn_verify_sig($alg, $pubkey, $clientDataJSON . $authenticatorData, $signature)) {
        webauthn_err('Passkey signature verification failed.');
    }
    $count = $parsed['signCount'];
    if ($storedCount !== 0 && $count !== 0 && $count < $storedCount) {
        webauthn_err('Passkey authenticator counter went backwards.');
    }
    return $count;
}