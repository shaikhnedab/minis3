<?php
// MiniS3 admin panel - single page app (no external dependencies).

declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require APP_ROOT . '/lib/util.php';
require APP_ROOT . '/lib/db.php';

db_init();

// The SPA must always be fetched fresh: a stale cached copy would keep
// running an outdated app shell (and outdated routing) after updates.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$appName = app_name();
$title = $appName . ' Admin';
$favPath = favicon_path();
$faviconUrl = '/favicon.ico' . ($favPath !== null && is_file($favPath) ? '?v=' . filemtime($favPath) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#F9F9FF">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#111318">
<title><?= htmlspecialchars($title) ?></title>
<link rel="icon" href="<?= htmlspecialchars($faviconUrl) ?>">
<style>
/* ============ Material 3 design tokens ============ */
:root{
    color-scheme:light;
    --primary:#0B57D0; --on-primary:#FFFFFF;
    --primary-container:#D3E3FD; --on-primary-container:#041E49;
    --secondary:#575E71; --on-secondary:#FFFFFF;
    --secondary-container:#DAE2F9; --on-secondary-container:#131B2C;
    --tertiary-container:#E5DFF5; --on-tertiary-container:#292043;
    --surface:#F9F9FF; --surface-1:#FFFFFF; --surface-2:#EEF0F8; --surface-3:#E8EAF4;
    --on-surface:#191C22; --on-surface-var:#44474E;
    --outline:#74777F; --outline-var:#C4C6D0;
    --error:#BA1A1A; --on-error:#FFFFFF; --error-container:#FFDAD6; --on-error-container:#410002;
    --ok:#146C2E; --ok-container:#C6F0D2; --on-ok-container:#072711;
    --warn:#8F5000; --warn-container:#FFDCBE; --on-warn-container:#2E1500;
    --inverse-surface:#2E3136; --inverse-on-surface:#F2F0F4; --inverse-primary:#A8C7FA;
    --row-hover:rgba(25,28,34,.045); --row-sel:rgba(11,87,208,.09);
    --scrim:rgba(20,24,32,.44);
    --shadow-1:0 1px 2px rgba(9,17,30,.10),0 1px 3px 1px rgba(9,17,30,.06);
    --shadow-2:0 1px 2px rgba(9,17,30,.12),0 2px 6px 2px rgba(9,17,30,.08);
    --shadow-3:0 4px 8px 3px rgba(9,17,30,.10),0 1px 3px rgba(9,17,30,.14);
    --ease-emph:cubic-bezier(.2,0,0,1);
    --ease-standard:cubic-bezier(.2,0,.2,1);
}
[data-theme="dark"]{
    color-scheme:dark;
    --primary:#A8C7FA; --on-primary:#062E6F;
    --primary-container:#0842A0; --on-primary-container:#D3E3FD;
    --secondary:#BFC6DC; --on-secondary:#293041;
    --secondary-container:#3F4759; --on-secondary-container:#DAE2F9;
    --tertiary-container:#4A4458; --on-tertiary-container:#E6DEFF;
    --surface:#111318; --surface-1:#1B1E24; --surface-2:#20242B; --surface-3:#262A31;
    --on-surface:#E3E2E9; --on-surface-var:#C4C6D0;
    --outline:#8E9099; --outline-var:#44474E;
    --error:#FFB4AB; --on-error:#690005; --error-container:#93000A; --on-error-container:#FFDAD6;
    --ok:#6DD58C; --ok-container:#0F5223; --on-ok-container:#C6F0D2;
    --warn:#FFB868; --warn-container:#6B3D00; --on-warn-container:#FFDCBE;
    --inverse-surface:#E3E2E9; --inverse-on-surface:#111318; --inverse-primary:#0B57D0;
    --row-hover:rgba(227,226,233,.05); --row-sel:rgba(168,199,250,.13);
    --scrim:rgba(0,0,0,.55);
    --shadow-1:0 1px 2px rgba(0,0,0,.5),0 1px 3px 1px rgba(0,0,0,.35);
    --shadow-2:0 1px 2px rgba(0,0,0,.55),0 2px 6px 2px rgba(0,0,0,.4);
    --shadow-3:0 4px 8px 3px rgba(0,0,0,.45),0 1px 3px rgba(0,0,0,.55);
}

/* ============ base ============ */
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
    margin:0;font-family:"Roboto Flex","Roboto",system-ui,-apple-system,"Segoe UI",Arial,sans-serif;
    background:var(--surface);color:var(--on-surface);font-size:14px;line-height:1.5;
    transition:background-color .25s var(--ease-standard),color .25s var(--ease-standard);
    min-height:100vh;display:flex;flex-direction:column;
}
h1,h2,h3,h4{margin:0;color:var(--on-surface)}
::selection{background:var(--primary-container);color:var(--on-primary-container)}
:focus-visible{outline:2px solid var(--primary);outline-offset:2px;border-radius:4px}
svg{display:block;flex:none}

/* ============ top app bar ============ */
#appBar{
    position:sticky;top:0;z-index:60;background:var(--surface);
    border-bottom:1px solid var(--outline-var);
    padding:10px 20px;display:flex;align-items:center;gap:12px;
}
.appbar-left{display:flex;align-items:center;gap:12px;flex:1;min-width:0}
.brand-mark{
    width:38px;height:38px;border-radius:12px;background:var(--primary);color:var(--on-primary);
    display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-1);
}
.brand-title{font-size:17px;font-weight:500;letter-spacing:.1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand-sub{color:var(--on-surface-var);font-weight:400}
.appbar-right{display:flex;align-items:center;gap:6px}
.user-chip{
    display:inline-flex;align-items:center;gap:8px;height:36px;padding:0 8px 0 6px;border-radius:999px;
    background:var(--secondary-container);color:var(--on-secondary-container);
    font-size:13px;font-weight:500;max-width:220px;white-space:nowrap;overflow:hidden;
}
.user-chip .chip-avatar{
    width:28px;height:28px;border-radius:999px;background:var(--primary);color:var(--on-primary);
    display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex:none;
}

/* ============ shell: rail + main ============ */
#shell{display:flex;flex:1;min-height:0;width:100%}
#navRail{
    position:sticky;top:58px;align-self:flex-start;height:calc(100vh - 58px);
    width:96px;flex:none;display:flex;flex-direction:column;align-items:center;
    gap:6px;padding:14px 8px 24px;
}
.nav-dest{
    position:relative;width:80px;border:0;background:transparent;color:var(--on-surface-var);
    display:flex;flex-direction:column;align-items:center;gap:5px;padding:4px 0 8px;border-radius:999px;
    cursor:pointer;font-family:inherit;overflow:hidden;
}
.nav-pill{
    width:56px;height:32px;border-radius:999px;background:transparent;
    display:flex;align-items:center;justify-content:center;transition:background .2s var(--ease-standard);
}
.nav-dest .nav-ic{height:24px;color:var(--on-surface-var);transition:color .2s var(--ease-standard)}
.nav-lbl{font-size:12px;font-weight:600;letter-spacing:.2px;line-height:1.2;text-align:center}
.nav-dest:hover .nav-pill{background:var(--row-hover)}
.nav-dest.active .nav-pill{background:var(--secondary-container)}
.nav-dest.active .nav-ic{color:var(--on-secondary-container)}
.nav-dest.active{color:var(--on-surface)}

#main{flex:1;min-width:0;padding:22px 26px 40px;max-width:1240px;margin:0 auto;width:100%}

/* bottom navigation (mobile) */
#bottomNav{
    position:fixed;left:0;right:0;bottom:0;z-index:60;display:none;
    background:var(--surface-2);box-shadow:var(--shadow-2);
    padding:8px 4px calc(8px + env(safe-area-inset-bottom));
    justify-content:space-around;
}

/* ============ buttons ============ */
button{font-family:inherit}
.btn{
    position:relative;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;gap:8px;
    height:40px;padding:0 22px;border-radius:999px;border:1px solid transparent;
    font-size:14px;font-weight:500;letter-spacing:.1px;cursor:pointer;white-space:nowrap;
    transition:box-shadow .15s var(--ease-standard),background-color .15s var(--ease-standard),color .15s var(--ease-standard);
    -webkit-tap-highlight-color:transparent;
}
.btn:disabled{opacity:.4;cursor:default;pointer-events:none}
.btn-filled{background:var(--primary);color:var(--on-primary)}
.btn-filled:hover{box-shadow:var(--shadow-1)}
.btn-tonal{background:var(--secondary-container);color:var(--on-secondary-container)}
.btn-tonal:hover{box-shadow:var(--shadow-1)}
.btn-outlined{background:transparent;color:var(--primary);border-color:var(--outline)}
.btn-outlined:hover{background:var(--row-hover)}
.btn-text{background:transparent;color:var(--primary);padding:0 14px}
.btn-text:hover{background:var(--row-hover)}
.btn-danger{background:var(--error-container);color:var(--on-error-container)}
.btn-danger:hover{box-shadow:var(--shadow-1)}
.btn-danger-filled{background:var(--error);color:var(--on-error)}
.btn-sm{height:32px;padding:0 14px;font-size:13px}
.btn-block{width:100%}
.icon-btn{
    position:relative;overflow:hidden;width:40px;height:40px;border-radius:999px;border:0;
    background:transparent;color:var(--on-surface-var);display:inline-flex;align-items:center;justify-content:center;
    cursor:pointer;transition:background-color .15s var(--ease-standard),color .15s var(--ease-standard);
    -webkit-tap-highlight-color:transparent;
}
.icon-btn:hover{background:var(--row-hover);color:var(--on-surface)}
.icon-btn.sm{width:32px;height:32px}
.icon-btn.danger:hover{color:var(--error)}
button.hidden,.hidden{display:none !important}

/* ripple */
.ripple{
    position:absolute;border-radius:999px;pointer-events:none;
    background:currentColor;opacity:.14;transform:scale(0);
    animation:rippleOut .5s var(--ease-standard) forwards;
}
@keyframes rippleOut{to{transform:scale(1);opacity:0}}

/* ============ FAB ============ */
.fab{
    position:fixed;right:26px;bottom:26px;z-index:55;
    height:56px;min-width:56px;padding:0 18px;border:0;border-radius:16px;
    background:var(--primary-container);color:var(--on-primary-container);
    display:inline-flex;align-items:center;gap:10px;font-size:14px;font-weight:600;letter-spacing:.1px;
    cursor:pointer;box-shadow:var(--shadow-2);overflow:hidden;
    transition:box-shadow .15s var(--ease-standard),transform .15s var(--ease-standard);
    -webkit-tap-highlight-color:transparent;
}
.fab:hover{box-shadow:var(--shadow-3)}
.fab:active{transform:scale(.97)}

/* ============ cards / panels ============ */
.card{
    background:var(--surface-1);border-radius:16px;padding:24px;max-width:440px;
    margin:40px auto;box-shadow:var(--shadow-1);
}
.card h2{font-size:24px;font-weight:400;margin-bottom:4px}
.card h3{font-size:16px;font-weight:500;letter-spacing:.1px}
.stat-panel{margin:0;max-width:none;min-width:0;animation:fadeUp .3s var(--ease-standard) both}
#topUsers,#recentActivity{min-width:0;overflow-x:auto;-webkit-overflow-scrolling:touch}
.muted{color:var(--on-surface-var)}
.error{color:var(--error);margin-top:10px;font-size:13px}

/* ============ text fields (M3 outlined, floating label) ============ */
.tf{position:relative;margin:16px 0}
.tf>input,.tf>select,.tf>textarea{
    width:100%;height:52px;padding:15px 15px;font-size:14.5px;color:var(--on-surface);
    background:transparent;border:1px solid var(--outline);border-radius:10px;outline:none;
    font-family:inherit;transition:border-color .15s var(--ease-standard),box-shadow .15s var(--ease-standard);
}
.tf>textarea{min-height:140px;resize:vertical;line-height:1.5;padding-top:22px}
.tf>label{
    position:absolute;left:11px;top:15px;font-size:14.5px;color:var(--on-surface-var);
    padding:0 5px;background:transparent;pointer-events:none;transition:all .15s var(--ease-standard);
}
.tf>input:focus,.tf>select:focus,.tf>textarea:focus{border-color:var(--primary);box-shadow:inset 0 0 0 1px var(--primary)}
.tf>input:focus+label,.tf>input:not(:placeholder-shown)+label,
.tf>textarea:focus+label,.tf>textarea:not(:placeholder-shown)+label,
.tf>select:focus+label,.tf>select.has-value+label,.tf.float>label{
    top:-9px;font-size:12px;background:var(--surface-1);
}
.tf>input:focus+label,.tf>select:focus+label{color:var(--primary)}
.tf>select{appearance:none;-webkit-appearance:none;cursor:pointer}
.tf .tf-caret{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--on-surface-var);pointer-events:none}

/* search field */
.searchfield{
    display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 16px;border-radius:999px;
    background:var(--surface-2);color:var(--on-surface-var);min-width:210px;flex:1;max-width:320px;
    transition:box-shadow .15s var(--ease-standard),background-color .15s var(--ease-standard);
}
.searchfield:focus-within{box-shadow:inset 0 0 0 2px var(--primary)}
.searchfield input{
    border:0;background:transparent;outline:none;color:var(--on-surface);font-size:13.5px;
    font-family:inherit;width:100%;padding:0;
}
.searchfield input::placeholder{color:var(--on-surface-var)}

/* switches */
.switch{display:flex;align-items:center;gap:12px;margin:10px 0;cursor:pointer;font-size:14px;color:var(--on-surface)}
.switch input{position:absolute;opacity:0;width:0;height:0}
.switch .track{
    width:52px;height:32px;border-radius:999px;background:var(--surface-3);
    border:2px solid var(--outline);position:relative;flex:none;
    transition:background-color .15s var(--ease-standard),border-color .15s var(--ease-standard);
}
.switch .thumb{
    position:absolute;left:6px;top:50%;transform:translateY(-50%);width:16px;height:16px;border-radius:999px;
    background:var(--outline);transition:left .18s var(--ease-emph),background-color .18s var(--ease-standard);
}
.switch input:checked+.track{background:var(--primary);border-color:var(--primary)}
.switch input:checked+.track .thumb{left:26px;background:var(--on-primary)}
.switch input:focus-visible+.track{outline:2px solid var(--primary);outline-offset:2px}
label.check{display:flex;align-items:center;gap:8px;margin:8px 0;color:var(--on-surface);cursor:pointer}
label.check input{width:18px;height:18px;accent-color:var(--primary);cursor:pointer}

/* ============ tables ============ */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.toolbar h3{flex:1;font-size:16px;font-weight:500;letter-spacing:.1px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tablewrap{
    overflow-x:auto;-webkit-overflow-scrolling:touch;background:var(--surface-1);
    border-radius:16px;box-shadow:var(--shadow-1);
}
.grid{width:100%;border-collapse:collapse}
.grid th{
    text-align:left;background:var(--surface-2);padding:12px 16px;font-size:11.5px;color:var(--on-surface-var);
    text-transform:uppercase;letter-spacing:.7px;font-weight:600;white-space:nowrap;
}
.grid td{padding:10px 16px;border-top:1px solid var(--outline-var);vertical-align:middle;font-size:13.5px}
.grid tr:hover td{background:var(--row-hover)}
tr.sel td{background:var(--row-sel) !important}
.dir td{background:transparent}
.actions{white-space:nowrap;text-align:right}
.actions .btn,.actions .icon-btn{margin-left:4px}
.link{
    position:relative;overflow:hidden;background:none;border:0;color:var(--primary);padding:6px 8px;margin:-6px -8px;
    font-size:14px;font-weight:500;text-align:left;cursor:pointer;border-radius:8px;font-family:inherit;
}
.link:hover{background:var(--row-hover)}
.cellname{display:flex;align-items:center;gap:10px;min-width:0}
.cellname .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.cellname .ficon{width:20px;height:20px;color:var(--on-surface-var);flex:none}
.cellname .ficon.folder{color:var(--primary)}
code{
    background:var(--surface-2);border-radius:6px;padding:2px 7px;font-size:12px;word-break:break-all;
    font-family:ui-monospace,"Cascadia Mono",Consolas,monospace;
}

/* status pills */
.st{display:inline-block;min-width:42px;text-align:center;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:600}
.st2{background:var(--ok-container);color:var(--on-ok-container)}
.st4{background:var(--warn-container);color:var(--on-warn-container)}
.st5{background:var(--error-container);color:var(--on-error-container)}

/* crumbs / path bar */
.pathbar{display:flex;flex-wrap:wrap;gap:2px;align-items:center;margin-bottom:12px;color:var(--on-surface-var);font-size:13px}
.crumb{
    position:relative;overflow:hidden;background:none;border:0;color:var(--primary);padding:6px 10px;
    font-size:13px;font-weight:500;cursor:pointer;border-radius:999px;font-family:inherit;
}
.crumb:hover{background:var(--row-hover)}
.crumb-sep{color:var(--on-surface-var);opacity:.6;user-select:none}

/* sort buttons */
.sortbtn{
    background:none;border:0;color:inherit;padding:0;font:inherit;text-transform:inherit;letter-spacing:inherit;
    cursor:pointer;display:inline-flex;align-items:center;gap:4px;font-weight:600;
}
.sortbtn:hover{color:var(--primary)}
.sortmark{font-size:10px;color:var(--primary);min-width:10px;text-align:center}
th.sorted .sortbtn{color:var(--primary)}

/* ============ bulk bar ============ */
.bulkbar{
    display:none;align-items:center;gap:8px;background:var(--secondary-container);color:var(--on-secondary-container);
    border-radius:999px;padding:6px 10px 6px 18px;margin-bottom:12px;box-shadow:var(--shadow-1);
    animation:fadeUp .2s var(--ease-standard) both;
}
.bulkbar.visible{display:flex}
.bulkbar b{flex:1;font-size:13.5px;font-weight:600}
.bulkbar .btn{background:transparent;color:var(--on-secondary-container);height:34px;padding:0 14px}
.bulkbar .btn:hover{background:var(--row-hover)}
.bulkbar .btn.btn-danger{background:var(--error-container);color:var(--on-error-container)}
input[type="checkbox"].rowcheck{width:18px;height:18px;cursor:pointer;accent-color:var(--primary)}

/* ============ pagination ============ */
.pager{display:flex;align-items:center;gap:8px;justify-content:flex-end;margin-top:14px;font-size:13px;color:var(--on-surface-var)}
.pager .btn{height:36px;padding:0 16px}
.pager select{
    padding:7px 10px;border:1px solid var(--outline);border-radius:999px;font-size:13px;
    background:var(--surface-1);color:var(--on-surface);font-family:inherit;cursor:pointer;outline:none;
}
.pager .pageinfo{min-width:110px;text-align:center}

/* ============ dropdown menus ============ */
.csel{position:relative;display:inline-block;min-width:150px;max-width:230px}
.csel-btn{
    width:100%;display:flex;justify-content:space-between;align-items:center;gap:8px;
    background:var(--surface-1);border:1px solid var(--outline);color:var(--on-surface);
    border-radius:999px;padding:9px 18px;font-size:13.5px;font-weight:500;cursor:pointer;font-family:inherit;
    transition:border-color .15s var(--ease-standard),box-shadow .15s var(--ease-standard);
}
.csel-btn:hover{border-color:var(--on-surface-var)}
.csel.open .csel-btn{border-color:var(--primary);box-shadow:inset 0 0 0 1px var(--primary)}
.csel-btn .caret{transition:transform .2s var(--ease-standard);color:var(--on-surface-var)}
.csel.open .csel-btn .caret{transform:rotate(180deg)}
.csel-list{
    position:absolute;top:calc(100% + 6px);left:0;min-width:180px;width:max-content;max-width:260px;
    background:var(--surface-2);border-radius:12px;box-shadow:var(--shadow-3);z-index:80;
    max-height:300px;overflow:auto;padding:8px;animation:menuIn .15s var(--ease-standard);
    transform-origin:top left;
}
@keyframes menuIn{from{opacity:0;transform:scale(.94) translateY(-4px)}to{opacity:1;transform:none}}
.csel-item{
    position:relative;overflow:hidden;display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:8px;
    cursor:pointer;font-size:13.5px;white-space:nowrap;overflow-x:hidden;text-overflow:ellipsis;color:var(--on-surface);
}
.csel-item:hover{background:var(--row-hover)}
.csel-item .chk{width:18px;color:var(--primary);visibility:hidden}
.csel-item.sel{background:var(--secondary-container);color:var(--on-secondary-container);font-weight:600}
.csel-item.sel .chk{visibility:visible}

/* ============ folder picker ============ */
.folder-picker{border:1px solid var(--outline-var);border-radius:12px;overflow:hidden;margin:10px 0;background:var(--surface-1)}
.fp-search{padding:8px}
.fp-search input{
    width:100%;border:0;border-radius:999px;padding:9px 14px;background:var(--surface-2);
    font-size:13px;color:var(--on-surface);font-family:inherit;outline:none;
}
.fp-search input:focus{box-shadow:inset 0 0 0 2px var(--primary)}
.fp-list{max-height:230px;overflow:auto;border-top:1px solid var(--outline-var)}
.fp-item{
    position:relative;overflow:hidden;padding:9px 14px;cursor:pointer;font-size:13.5px;
    white-space:nowrap;overflow-x:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:8px;color:var(--on-surface);
}
.fp-item:hover{background:var(--row-hover)}
.fp-item.sel{background:var(--secondary-container);color:var(--on-secondary-container);font-weight:600}
.fp-item svg{width:17px;height:17px;color:var(--primary)}

/* ============ upload dialog ============ */
.upload-row{display:flex;align-items:center;gap:12px;padding:7px 4px;font-size:13.5px}
.upload-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.upload-state{min-width:84px;text-align:right;font-size:12px;color:var(--on-surface-var);word-break:break-word}
.upload-state.ok{color:var(--ok);font-weight:600}
.upload-state.err{color:var(--error);font-weight:600}
.upload-state.warn{color:var(--warn);font-weight:600}
.conflict-note{
    display:flex;gap:10px;align-items:flex-start;background:var(--warn-container);color:var(--on-warn-container);
    border-radius:12px;padding:12px 14px;font-size:13px;
}
.upload-progress{display:flex;align-items:center;gap:12px;margin-top:12px}
.progress{flex:1;height:5px;background:var(--surface-2);border-radius:999px;overflow:hidden}
.progress div{height:100%;width:0;background:var(--primary);border-radius:999px;transition:width .2s var(--ease-standard)}

/* ============ media / editor modals ============ */
#modalBox.wide{max-width:880px;width:94%}
.media-wrap{min-height:60px;text-align:center;color:var(--on-surface-var);display:flex;align-items:center;justify-content:center}
.media-img{max-width:100%;max-height:65vh;display:block;border-radius:12px;margin:0 auto;background:var(--surface-2)}
.media-video{max-width:100%;max-height:65vh;border-radius:12px;display:block;margin:0 auto;background:#000}
.media-audio{width:100%}
.media-pdf{width:100%;height:70vh;border:0;border-radius:12px;background:var(--surface-2)}
#textContent,#fileContent{
    width:100%;min-height:340px;resize:vertical;border:1px solid var(--outline);border-radius:12px;
    background:var(--surface-1);color:var(--on-surface);padding:14px;
    font-family:ui-monospace,"Cascadia Mono",Consolas,"Courier New",monospace;font-size:13px;line-height:1.55;
    white-space:pre;overflow:auto;tab-size:4;outline:none;
}
#textContent:focus,#fileContent:focus{border-color:var(--primary);box-shadow:inset 0 0 0 1px var(--primary)}
.editor-hint{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
.editor-hint .muted{font-size:12px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ============ dashboard ============ */
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:16px}
.stat-card{
    background:var(--surface-1);border-radius:16px;padding:18px;box-shadow:var(--shadow-1);
    display:flex;align-items:center;gap:14px;animation:fadeUp .35s var(--ease-standard) both;
    transition:box-shadow .2s var(--ease-standard),transform .2s var(--ease-standard);
}
.stat-card:nth-child(2){animation-delay:.04s}.stat-card:nth-child(3){animation-delay:.08s}
.stat-card:nth-child(4){animation-delay:.12s}.stat-card:nth-child(5){animation-delay:.16s}
.stat-card:nth-child(6){animation-delay:.2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-2)}
.stat-ic{
    width:44px;height:44px;border-radius:999px;background:var(--primary-container);color:var(--on-primary-container);
    display:flex;align-items:center;justify-content:center;flex:none;
}
.stat-body{min-width:0}
.stat-label{display:block;font-size:11.5px;color:var(--on-surface-var);text-transform:uppercase;letter-spacing:.7px;font-weight:600}
.stat-value{display:block;font-size:23px;font-weight:500;margin-top:2px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.stacked{display:flex;height:16px;border-radius:999px;overflow:hidden;background:var(--surface-2)}
.stacked>div{height:100%;width:0;transition:width .7s var(--ease-emph)}
.bar-2xx{background:var(--ok)}
.bar-4xx{background:var(--warn)}
.bar-5xx{background:var(--error)}
.legend{display:flex;gap:16px;margin-top:12px;font-size:12.5px;color:var(--on-surface-var);flex-wrap:wrap}
.legend b{color:var(--on-surface)}
.legend span::before{content:"";display:inline-block;width:10px;height:10px;border-radius:4px;margin-right:6px;vertical-align:-1px}
.legend .l2xx::before{background:var(--ok)}
.legend .l4xx::before{background:var(--warn)}
.legend .l5xx::before{background:var(--error)}
.chart{display:flex;align-items:flex-end;gap:3px;height:120px;padding-top:10px}
.chart .col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;min-width:0}
.chart .bar{
    width:100%;max-width:26px;background:var(--primary);border-radius:6px 6px 2px 2px;height:3px;
    transition:height .5s var(--ease-emph),filter .15s var(--ease-standard);cursor:default;
}
.chart .bar:hover{filter:brightness(1.12)}
.chart .h{font-size:9.5px;color:var(--on-surface-var);margin-top:6px;white-space:nowrap;overflow:hidden}
.tu-row{display:flex;align-items:center;gap:10px;padding:5px 0;font-size:13.5px;min-width:0}
.tu-row>span:first-child{width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500;flex:none}
.tu-bar{flex:1;height:6px;background:var(--surface-2);border-radius:999px;overflow:hidden;min-width:0}
.tu-bar div{height:100%;width:0;background:var(--primary);border-radius:999px;transition:width .6s var(--ease-emph)}
.ra-row{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--outline-var);font-size:13px;width:max-content;min-width:100%}
.ra-row:last-child{border-bottom:0}
.ra-uri{flex:0 0 auto;min-width:0;overflow:hidden;white-space:nowrap;color:var(--on-surface)}
.files-meta{color:var(--on-surface-var);font-size:13px;background:var(--surface-2);border-radius:999px;padding:5px 12px;white-space:nowrap}

/* empty states */
.empty{display:flex;flex-direction:column;align-items:center;gap:10px;padding:38px 16px;color:var(--on-surface-var);font-size:13.5px;text-align:center}
.empty svg{width:36px;height:36px;opacity:.5}

/* ---------- row context menu ---------- */
.ctxmenu{
    position:fixed;z-index:110;min-width:190px;background:var(--surface-2);border-radius:12px;
    box-shadow:var(--shadow-3);padding:8px;animation:menuIn .12s var(--ease-standard);transform-origin:top left;
}
.ctxmenu .ci{
    position:relative;overflow:hidden;display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:0;
    border-radius:8px;background:transparent;color:var(--on-surface);font-size:13.5px;font-family:inherit;
    cursor:pointer;text-align:left;white-space:nowrap;
}
.ctxmenu .ci:hover{background:var(--row-hover)}
.ctxmenu .ci.danger{color:var(--error)}
.ctxmenu .ci svg{width:18px;height:18px;flex:none;opacity:.8}

/* ---------- file grid view ---------- */
.gridview{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;animation:fadeUp .2s var(--ease-standard) both}
.gcard{
    position:relative;overflow:hidden;background:var(--surface-1);border-radius:16px;padding:12px;cursor:pointer;
    box-shadow:var(--shadow-1);display:flex;flex-direction:column;gap:8px;border:1px solid transparent;
    transition:box-shadow .15s var(--ease-standard),border-color .15s var(--ease-standard);
}
.gcard:hover{box-shadow:var(--shadow-2);border-color:var(--outline-var)}
.gcard.sel{border-color:var(--primary);background:var(--surface-1)}
.gthumb{
    height:96px;border-radius:10px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;
    color:var(--on-surface-var);overflow:hidden;
}
.gthumb img{width:100%;height:100%;object-fit:cover;display:block}
.gthumb svg{width:36px;height:36px}
.gthumb.folder{color:var(--primary)}
.gname{font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gsub{font-size:11.5px;color:var(--on-surface-var)}

/* ---------- drag & drop ---------- */
#filesView.droptarget .tablewrap,#filesView.droptarget .gridview{
    outline:2px dashed var(--primary);outline-offset:4px;border-radius:16px;
}
.dropveil{
    position:absolute;inset:0;z-index:5;display:flex;align-items:center;justify-content:center;
    background:var(--scrim);border-radius:16px;color:#fff;font-size:15px;font-weight:500;pointer-events:none;
}

/* ---------- quota bars & sparklines ---------- */
.quota-bar{height:5px;background:var(--surface-2);border-radius:999px;overflow:hidden;margin-top:5px;width:110px}
.quota-bar div{height:100%;background:var(--primary);border-radius:999px}
.quota-bar.over div{background:var(--error)}
.spark{display:block;color:var(--primary);opacity:.85}

/* ---------- kbd hints ---------- */
.kbd{
    display:inline-block;padding:1px 6px;border-radius:6px;border:1px solid var(--outline-var);
    background:var(--surface-2);font-family:ui-monospace,Consolas,monospace;font-size:11px;color:var(--on-surface-var);
}

/* ============ dialogs / overlays / snackbar ============ */
#modalOverlay{
    position:fixed;inset:0;background:var(--scrim);display:flex;align-items:center;justify-content:center;
    z-index:90;padding:18px;animation:fadeIn .15s var(--ease-standard);
}
#modalBox{
    background:var(--surface-3);border-radius:28px;padding:24px;min-width:320px;max-width:540px;width:100%;
    max-height:88vh;overflow:auto;box-shadow:var(--shadow-3);animation:scaleIn .22s var(--ease-emph);
}
#modalBox h3{font-size:20px;font-weight:500;letter-spacing:.1px;margin-bottom:14px;overflow-wrap:anywhere}
#modalBox p{color:var(--on-surface-var);font-size:13.5px}
.modal-actions{display:flex;gap:8px;margin-top:20px;justify-content:flex-end;flex-wrap:wrap}
#toast{
    position:fixed;bottom:26px;left:50%;transform:translateX(-50%);max-width:min(560px,92vw);
    background:var(--inverse-surface);color:var(--inverse-on-surface);padding:13px 20px;border-radius:12px;
    font-size:13.5px;font-weight:500;box-shadow:var(--shadow-3);z-index:120;display:flex;gap:10px;align-items:center;
}
#toast.toast-ok{background:var(--inverse-surface)}
#toast.toast-ok .t-ic{color:var(--ok)}
#toast.toast-err{background:var(--error);color:var(--on-error)}
#toast.toast-err .t-ic{color:var(--on-error)}
#toast:not(.hidden){animation:toastIn .25s var(--ease-emph)}
#loadingOverlay{
    position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
    background:var(--scrim);z-index:130;animation:fadeIn .15s var(--ease-standard);
}
.spinner{
    width:46px;height:46px;border-radius:999px;border:4px solid var(--primary-container);
    border-top-color:var(--primary);animation:spin .8s linear infinite;
}
#loadingOverlay .lbl{font-size:13.5px;color:var(--inverse-on-surface)}

/* ============ animations ============ */
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes scaleIn{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}
@keyframes toastIn{from{opacity:0;transform:translate(-50%,14px)}to{opacity:1;transform:translate(-50%,0)}}
@keyframes spin{to{transform:rotate(360deg)}}
.tab-panel{animation:fadeUp .25s var(--ease-standard)}
.grid tbody tr{animation:fadeIn .2s var(--ease-standard)}
body.busy .grid tbody,body.busy .cards,body.busy .stat-panel{opacity:.55;transition:opacity .2s var(--ease-standard)}
@media (prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.01ms !important;transition-duration:.01ms !important}
}

/* ============ login ============ */
.login-card{text-align:center}
.login-logo{
    width:60px;height:60px;border-radius:18px;background:var(--primary);color:var(--on-primary);
    display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:var(--shadow-2);
}
.login-card form{text-align:left}

/* ============ responsive ============ */
@media(max-width:900px){.fab .fab-lbl{display:none}.fab{padding:0;justify-content:center}}
@media(max-width:768px){
    body{overflow-x:hidden}
    #appBar{padding:8px 12px}
    .brand-title{font-size:15px}
    .brand-sub{display:none}
    #navRail{display:none}
    #bottomNav{display:flex}
    #main{padding:14px 14px calc(104px + env(safe-area-inset-bottom))}
    .toolbar{gap:8px;align-items:stretch}
    .toolbar h3{flex-basis:calc(100% - 52px)}
    .searchfield{max-width:none;flex:1 1 100%}
    .csel{max-width:none;flex:1 1 140px}
    .csel-list{max-width:none;width:100%}
    .files-meta{margin:0;flex-basis:100%;align-self:flex-start}
    .bulkbar{flex-wrap:wrap;border-radius:16px;padding:8px 10px 8px 16px}
    .bulkbar b{flex-basis:100%}
    .pager{flex-wrap:wrap;justify-content:center;gap:8px}
    .pager .pageinfo{min-width:0;flex-basis:100%;text-align:center}
    .grid th,.grid td{padding:9px 10px}
    #filesView .grid th:nth-child(3),#filesView .grid th:nth-child(4),#filesView .grid th:nth-child(5),
    #filesView .grid td:nth-child(3),#filesView .grid td:nth-child(4),#filesView .grid td:nth-child(5){display:none}
    #tab-users .grid th:nth-child(2),#tab-users .grid th:nth-child(3),#tab-users .grid th:nth-child(5),
    #tab-users .grid td:nth-child(2),#tab-users .grid td:nth-child(3),#tab-users .grid td:nth-child(5){display:none}
    #filesView .grid td:nth-child(2){min-width:130px;max-width:230px}
    .actions{white-space:normal;display:flex;flex-wrap:wrap;justify-content:flex-end;gap:4px}
    .actions .btn{margin-left:0}
    #modalBox{min-width:0;width:100%;padding:20px;border-radius:24px}
    #modalBox.wide{width:100%}
    .modal-actions{flex-wrap:wrap}
    .cards{grid-template-columns:repeat(2,1fr);gap:10px}
    .stat-card{flex-direction:column;align-items:flex-start;gap:10px;padding:14px}
    .stat-value{font-size:20px}
    .stats-grid{grid-template-columns:1fr}
    .tu-row>span:first-child{width:96px}
    #toast{width:calc(100% - 24px);left:12px;transform:none;bottom:calc(96px + env(safe-area-inset-bottom))}
    #toast:not(.hidden){animation:toastInMobile .25s var(--ease-emph)}
    .fab{right:16px;bottom:calc(92px + env(safe-area-inset-bottom))}
    .editor-hint{flex-wrap:wrap}
}
@keyframes toastInMobile{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
@media(max-width:400px){
    .user-chip{display:none}
    .card{margin:24px auto;padding:20px}
    #main{padding:12px 10px calc(104px + env(safe-area-inset-bottom))}
}
</style>
</head>
<body>

<header id="appBar">
  <div class="appbar-left">
    <span class="brand-mark" data-icon="bucket-logo"></span>
    <h1 class="brand-title"><span id="brandName"><?= htmlspecialchars($appName) ?></span> <span class="brand-sub">Admin</span></h1>
  </div>
  <div class="appbar-right">
    <span id="headerUser" class="user-chip hidden"></span>
    <button id="themeBtn" class="icon-btn" title="Toggle dark / light theme" aria-label="Toggle dark / light theme"></button>
    <button id="logoutBtn" class="icon-btn hidden" data-icon="log-out" title="Log out" aria-label="Log out"></button>
  </div>
</header>

<div id="shell">
  <nav id="navRail" class="hidden" aria-label="Sections">
    <button class="nav-dest active" data-tab="stats"><span class="nav-pill"><span class="nav-ic" data-icon="grid"></span></span><span class="nav-lbl">Dashboard</span></button>
    <button class="nav-dest" data-tab="users"><span class="nav-pill"><span class="nav-ic" data-icon="users"></span></span><span class="nav-lbl">Users</span></button>
    <button class="nav-dest" data-tab="buckets"><span class="nav-pill"><span class="nav-ic" data-icon="hard-drive"></span></span><span class="nav-lbl">Buckets</span></button>
    <button class="nav-dest" data-tab="logs"><span class="nav-pill"><span class="nav-ic" data-icon="file-text"></span></span><span class="nav-lbl">Logs</span></button>
    <button class="nav-dest" data-tab="trash"><span class="nav-pill"><span class="nav-ic" data-icon="trash"></span></span><span class="nav-lbl">Trash</span></button>
    <button class="nav-dest" data-tab="settings"><span class="nav-pill"><span class="nav-ic" data-icon="sliders"></span></span><span class="nav-lbl">Settings</span></button>
  </nav>

  <main id="main">
    <div id="loginBox" class="hidden">
      <div class="card login-card">
        <div class="login-logo" data-icon="bucket-logo"></div>
        <h2>Sign in</h2>
        <p class="muted" style="margin:0 0 6px">Admin credentials (set during installation)</p>
        <form id="loginForm">
          <div class="tf">
            <input type="text" id="loginUsername" autocomplete="username" required placeholder=" ">
            <label for="loginUsername">Username</label>
          </div>
          <div class="tf">
            <input type="password" id="loginPassword" autocomplete="current-password" required placeholder=" ">
            <label for="loginPassword">Password</label>
          </div>
          <div class="tf hidden" id="loginCodeWrap">
            <input type="text" id="loginCode" inputmode="numeric" autocomplete="one-time-code" placeholder=" " maxlength="6">
            <label for="loginCode">Two-factor code</label>
          </div>
          <button type="submit" class="btn btn-filled btn-block" style="margin-top:20px">Sign in</button>
          <button type="button" id="passkeyLoginBtn" class="btn btn-tonal btn-block hidden" style="margin-top:10px"><span class="bi" data-icon="key"></span>Sign in with passkey</button>
          <div id="loginError" class="error hidden"></div>
        </form>
      </div>
    </div>

    <div id="appBox" class="hidden">
      <section id="tab-stats" class="tab-panel">
        <div class="toolbar">
          <h3>Overview</h3>
          <button id="refreshStatsBtn" class="btn btn-tonal"><span class="bi" data-icon="refresh"></span>Refresh</button>
        </div>
        <div class="cards">
          <div class="stat-card"><span class="stat-ic" data-icon="users"></span><span class="stat-body"><span class="stat-label">Users</span><span class="stat-value" id="stUsers">&ndash;</span></span></div>
          <div class="stat-card"><span class="stat-ic" data-icon="hard-drive"></span><span class="stat-body"><span class="stat-label">Buckets</span><span class="stat-value" id="stBuckets">&ndash;</span></span></div>
          <div class="stat-card"><span class="stat-ic" data-icon="box"></span><span class="stat-body"><span class="stat-label">Objects</span><span class="stat-value" id="stObjects">&ndash;</span></span></div>
          <div class="stat-card"><span class="stat-ic" data-icon="database"></span><span class="stat-body"><span class="stat-label">Storage used</span><span class="stat-value" id="stSize">&ndash;</span></span></div>
          <div class="stat-card"><span class="stat-ic" data-icon="activity"></span><span class="stat-body"><span class="stat-label">Requests</span><span class="stat-value" id="stRequests">&ndash;</span></span></div>
          <div class="stat-card"><span class="stat-ic" data-icon="zap"></span><span class="stat-body"><span class="stat-label">Avg response</span><span class="stat-value" id="stAvgMs">&ndash;</span></span></div>
        </div>
        <div class="stats-grid">
          <div class="card stat-panel">
            <h3 style="margin-bottom:14px">Request status distribution</h3>
            <div class="stacked"><div class="bar-2xx" id="bar2xx"></div><div class="bar-4xx" id="bar4xx"></div><div class="bar-5xx" id="bar5xx"></div></div>
            <div class="legend">
              <span class="l2xx">2xx <b id="lbl2xx">0</b></span>
              <span class="l4xx">4xx <b id="lbl4xx">0</b></span>
              <span class="l5xx">5xx <b id="lbl5xx">0</b></span>
            </div>
          </div>
          <div class="card stat-panel">
            <h3 style="margin-bottom:14px">Requests last 24 hours</h3>
            <div class="chart" id="chart24"></div>
          </div>
        </div>
        <div class="stats-grid">
          <div class="card stat-panel">
            <h3 style="margin-bottom:10px">Top users by requests</h3>
            <div id="topUsers"></div>
          </div>
          <div class="card stat-panel">
            <h3 style="margin-bottom:10px">Recent activity</h3>
            <div id="recentActivity"></div>
          </div>
        </div>
      </section>

      <section id="tab-users" class="tab-panel hidden">
        <div class="toolbar">
          <h3>S3 users (access key + secret key)</h3>
          <button id="addUserBtn" class="btn btn-filled"><span class="bi" data-icon="user-plus"></span>Add user</button>
        </div>
        <div class="tablewrap">
          <table class="grid">
            <thead><tr>
              <th><button type="button" class="sortbtn" data-usort="username">Username<span class="sortmark"></span></button></th>
              <th>Access key</th><th>Secret key</th>
              <th><button type="button" class="sortbtn" data-usort="storage_used">Storage<span class="sortmark"></span></button></th>
              <th><button type="button" class="sortbtn" data-usort="created_at">Created<span class="sortmark"></span></button></th>
              <th style="width:200px"></th></tr></thead>
            <tbody id="usersTbody"></tbody>
          </table>
        </div>
      </section>

      <section id="tab-buckets" class="tab-panel hidden">
        <div id="bucketView">
          <div class="toolbar">
            <h3>Buckets</h3>
            <select id="bucketUserSelect" class="hidden"><option value="">All users</option></select>
            <button id="addBucketBtn" class="btn btn-filled"><span class="bi" data-icon="plus"></span>Add bucket</button>
          </div>
          <div class="tablewrap">
            <table class="grid">
              <thead><tr>
              <th><button type="button" class="sortbtn" data-bsort="name">Name<span class="sortmark"></span></button></th>
              <th><button type="button" class="sortbtn" data-bsort="username">User<span class="sortmark"></span></button></th>
              <th><button type="button" class="sortbtn" data-bsort="object_count">Objects<span class="sortmark"></span></button></th>
              <th><button type="button" class="sortbtn" data-bsort="created_at">Created<span class="sortmark"></span></button></th>
              <th style="width:240px"></th></tr></thead>
              <tbody id="bucketsTbody"></tbody>
            </table>
          </div>
        </div>
        <div id="filesView" class="hidden">
        <div class="toolbar">
          <button id="backToBucketsBtn" class="icon-btn" data-icon="arrow-left" title="Back to buckets" aria-label="Back to buckets"></button>
          <h3 id="filesTitle"></h3>
          <span class="files-meta" id="filesMeta"></span>
          <div class="searchfield"><span data-icon="search"></span><input type="search" id="objSearch" placeholder="Search keys...  (/)" autocomplete="off" aria-label="Search keys"></div>
          <button id="zipBtn" class="btn btn-tonal btn-sm" title="Download this folder as ZIP"><span class="bi" data-icon="download"></span>ZIP</button>
          <button id="newFolderBtn" class="btn btn-tonal btn-sm"><span class="bi" data-icon="folder-plus"></span>Folder</button>
          <button id="newFileBtn" class="btn btn-tonal btn-sm"><span class="bi" data-icon="file-plus"></span>File</button>
          <input type="file" id="uploadInput" class="hidden" multiple>
          <button id="viewToggleBtn" class="icon-btn" title="Switch list / grid view" aria-label="Switch list / grid view"></button>
          <button id="refreshFilesBtn" class="icon-btn" data-icon="refresh" title="Refresh" aria-label="Refresh"></button>
          <button id="deleteFolderBtn" class="btn btn-danger btn-sm hidden"><span class="bi" data-icon="trash"></span>Delete folder</button>
        </div>
          <div class="pathbar" id="pathbar"></div>
          <div class="bulkbar" id="bulkBar">
            <b id="bulkLabel"></b>
            <button id="bulkCopyBtn" class="btn"><span class="bi" data-icon="copy"></span>Copy</button>
            <button id="bulkMoveBtn" class="btn"><span class="bi" data-icon="corner-up-right"></span>Move</button>
            <button id="bulkDeleteBtn" class="btn btn-danger"><span class="bi" data-icon="trash"></span>Delete</button>
            <button id="bulkClearBtn" class="btn">Clear</button>
          </div>
        <div class="tablewrap" id="filesTableWrap">
          <table class="grid">
            <thead><tr><th style="width:40px"><input type="checkbox" id="selAll" title="Select all on this page" aria-label="Select all on this page"></th>
              <th><button type="button" class="sortbtn" data-sort="name">Name<span class="sortmark"></span></button></th>
              <th><button type="button" class="sortbtn" data-sort="size">Size<span class="sortmark"></span></button></th>
              <th><button type="button" class="sortbtn" data-sort="modified">Modified<span class="sortmark"></span></button></th>
              <th><button type="button" class="sortbtn" data-sort="type">Type<span class="sortmark"></span></button></th>
              <th style="width:170px"></th></tr></thead>
            <tbody id="filesTbody"></tbody>
          </table>
        </div>
        <div id="fileGrid" class="gridview hidden"></div>
        <div class="pager" id="objPager"></div>
          <button id="uploadFileBtn" class="fab" aria-label="Upload files"><span data-icon="upload"></span><span class="fab-lbl">Upload</span></button>
        </div>
      </section>

      <section id="tab-logs" class="tab-panel hidden">
        <div class="toolbar">
          <h3>Request logs</h3>
          <div class="searchfield"><span data-icon="search"></span><input type="search" id="logSearch" placeholder="Search uri / ip / method..." autocomplete="off" aria-label="Search logs"></div>
          <select id="logUser" class="hidden"><option value="">All users</option></select>
          <select id="logKind" class="hidden">
            <option value="">All kinds</option>
            <option value="s3">S3 API</option>
            <option value="admin">Admin</option>
          </select>
          <select id="logMethod" class="hidden">
            <option value="">All methods</option>
            <option value="GET">GET</option>
            <option value="PUT">PUT</option>
            <option value="POST">POST</option>
            <option value="DELETE">DELETE</option>
            <option value="HEAD">HEAD</option>
          </select>
          <select id="logStatus" class="hidden">
            <option value="">All statuses</option>
            <option value="2xx">2xx</option>
            <option value="4xx">4xx</option>
            <option value="5xx">5xx</option>
          </select>
          <button id="refreshLogsBtn" class="icon-btn" data-icon="refresh" title="Refresh" aria-label="Refresh"></button>
          <button id="clearLogsBtn" class="btn btn-danger btn-sm"><span class="bi" data-icon="trash"></span>Clear logs</button>
        </div>
        <div id="logHint" class="muted hidden" style="margin:-4px 0 10px"></div>
        <div class="tablewrap">
          <table class="grid">
            <thead><tr><th>Time</th><th>User</th><th>IP</th><th>Method</th><th>URI</th><th>Status</th><th>Bytes</th><th>ms</th></tr></thead>
            <tbody id="logsTbody"></tbody>
          </table>
        </div>
        <div class="pager" id="logPager"></div>
      </section>

      <section id="tab-trash" class="tab-panel hidden">
        <div class="toolbar">
          <h3>Trash</h3>
          <span class="files-meta hidden" id="trashMeta"></span>
          <button id="refreshTrashBtn" class="icon-btn" data-icon="refresh" title="Refresh" aria-label="Refresh"></button>
          <button id="emptyTrashBtn" class="btn btn-danger btn-sm"><span class="bi" data-icon="trash"></span>Empty trash</button>
        </div>
        <div id="trashHint" class="muted hidden" style="margin:-4px 0 10px"></div>
        <div class="tablewrap">
          <table class="grid">
            <thead><tr><th>Object</th><th>Bucket</th><th>User</th><th>Size</th><th>Deleted</th><th>Expires</th><th style="width:200px"></th></tr></thead>
            <tbody id="trashTbody"></tbody>
          </table>
        </div>
        <div class="pager" id="trashPager"></div>
      </section>

      <section id="tab-settings" class="tab-panel hidden">
        <div class="card" style="margin:0 0 16px;max-width:480px">
          <h3>Branding</h3>
          <form id="brandingForm">
            <div class="tf">
              <input type="text" name="app_name" id="appNameInput" maxlength="40" placeholder=" " autocomplete="off">
              <label for="appNameInput">App name (shown in title and header)</label>
            </div>
            <button type="submit" class="btn btn-filled">Save name</button>
            <div id="brandingError" class="error hidden"></div>
          </form>
          <p style="margin:18px 0 8px">Favicon</p>
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <img id="faviconPreview" src="<?= htmlspecialchars($faviconUrl) ?>" alt="Current favicon" style="width:36px;height:36px;border-radius:9px;background:var(--surface-2);object-fit:contain;padding:3px">
            <input type="file" id="faviconInput" class="hidden" accept=".png,.gif,.jpg,.jpeg,.svg,.ico,.webp">
            <button id="faviconUploadBtn" class="btn btn-tonal btn-sm"><span class="bi" data-icon="upload"></span>Upload</button>
            <button id="faviconResetBtn" class="btn btn-outlined btn-sm">Use default</button>
          </div>
          <p class="muted" style="margin:10px 0 0;font-size:12.5px">PNG, GIF, JPG, SVG, ICO or WebP, up to 1 MB. Browsers may cache the old icon for a while.</p>
        </div>
        <div class="card" style="margin:0 0 16px;max-width:480px">
          <h3>Request logging</h3>
          <p class="muted" style="margin:2px 0 6px">Disable to stop new entries from being written to the logs.</p>
          <label class="switch"><input type="checkbox" id="logS3"><span class="track"><span class="thumb"></span></span><span>Log S3 API requests</span></label>
          <label class="switch"><input type="checkbox" id="logAdmin"><span class="track"><span class="thumb"></span></span><span>Log admin panel requests</span></label>
          <button id="saveLogsBtn" class="btn btn-filled" style="margin-top:12px">Save</button>
          <div id="logsError" class="error hidden"></div>
        </div>
        <div class="card" style="margin:0 0 16px;max-width:480px">
          <h3>Admin account</h3>
          <form id="profileForm">
            <div class="tf">
              <input type="text" name="username" id="profileUsername" required pattern="[A-Za-z0-9._\-]{1,64}" autocomplete="off" placeholder=" ">
              <label for="profileUsername">Username</label>
            </div>
            <button type="submit" class="btn btn-filled">Save username</button>
            <div id="profileError" class="error hidden"></div>
          </form>
        </div>
        <div class="card" style="margin:0 0 16px;max-width:480px">
          <h3>Change admin password</h3>
          <form id="settingsForm">
            <div class="tf"><input type="password" name="current" id="pwCurrent" required placeholder=" "><label for="pwCurrent">Current password</label></div>
            <div class="tf"><input type="password" name="new" id="pwNew" required minlength="8" placeholder=" "><label for="pwNew">New password</label></div>
            <div class="tf"><input type="password" name="new2" id="pwNew2" required placeholder=" "><label for="pwNew2">Repeat new password</label></div>
            <button type="submit" class="btn btn-filled">Change password</button>
            <div id="settingsError" class="error hidden"></div>
          </form>
        </div>
        <div class="card" style="margin:0 0 16px;max-width:480px">
          <h3>Trash</h3>
          <p class="muted" style="margin:2px 0 6px">Files deleted in the admin panel are kept for the given number of days and can be restored from the Trash tab. 0 = delete permanently.</p>
          <form id="trashForm">
            <div class="tf" style="max-width:220px"><input type="number" name="trash_days" id="trashDays" min="0" max="365" required placeholder=" "><label for="trashDays">Keep deleted files (days)</label></div>
            <button type="submit" class="btn btn-filled">Save</button>
            <div id="trashError" class="error hidden"></div>
          </form>
        </div>
        <div class="card" style="margin:0 0 16px;max-width:480px">
          <h3>Two-factor authentication</h3>
          <p class="muted" style="margin:2px 0 10px">Require a time-based one-time code (TOTP) from an authenticator app in addition to your password.</p>
          <div id="totpStatus" class="muted" style="margin-bottom:10px"></div>
          <div id="totpSetup" class="hidden">
            <p style="margin:0 0 6px">1. Add this secret to your authenticator app:</p>
            <code id="totpSecret" style="display:block;padding:10px 12px;margin-bottom:10px;overflow-wrap:anywhere"></code>
            <p style="margin:0 0 6px">2. Or open the <a id="totpLink" href="#">otpauth link</a> on this device.</p>
            <form id="totpEnableForm">
              <div class="tf" style="max-width:220px"><input type="text" id="totpCode" inputmode="numeric" maxlength="6" placeholder=" " autocomplete="one-time-code"><label for="totpCode">6-digit code</label></div>
              <button type="submit" class="btn btn-filled">Verify &amp; enable</button>
            </form>
          </div>
          <button id="totpEnableBtn" class="btn btn-tonal hidden"><span class="bi" data-icon="key"></span>Enable 2FA</button>
          <form id="totpDisableForm" class="hidden">
            <div class="tf"><input type="password" id="totpPw" required placeholder=" "><label for="totpPw">Confirm with your password</label></div>
            <button type="submit" class="btn btn-danger">Disable 2FA</button>
          </form>
        </div>
        <div class="card" style="margin:0 0 16px;max-width:480px">
          <h3>Passkeys</h3>
          <p class="muted" style="margin:2px 0 10px">Sign in without a password using a passkey (Face ID, Windows Hello, security key or password manager). Passkeys work in addition to the password and 2FA.</p>
          <div id="passkeyList"></div>
          <button id="addPasskeyBtn" class="btn btn-tonal"><span class="bi" data-icon="key"></span>Add passkey</button>
          <div id="passkeyError" class="error hidden"></div>
        </div>
        <div class="card" style="margin:0;max-width:640px">
          <div class="toolbar" style="margin-bottom:10px">
            <h3 style="flex:1">In-progress multipart uploads</h3>
            <button id="refreshUploadsBtn" class="icon-btn" data-icon="refresh" title="Refresh" aria-label="Refresh"></button>
            <button id="cleanupUploadsBtn" class="btn btn-tonal btn-sm"><span class="bi" data-icon="trash"></span>Cleanup &gt; 7 days</button>
          </div>
          <p class="muted" style="margin:0 0 8px">Uploads that were started but never completed hold disk space until aborted.</p>
          <div class="tablewrap"><table class="grid">
            <thead><tr><th>Key</th><th>Bucket</th><th>User</th><th>Size</th><th>Started</th><th></th></tr></thead>
            <tbody id="uploadsTbody"></tbody>
          </table></div>
        </div>
        <p class="muted" style="margin:18px 0 0;font-size:12.5px;text-align:center">MiniS3 v<span id="appVersion"></span></p>
      </section>
    </div>
  </main>
</div>

<nav id="bottomNav" class="hidden" aria-label="Sections">
  <button class="nav-dest active" data-tab="stats"><span class="nav-pill"><span class="nav-ic" data-icon="grid"></span></span><span class="nav-lbl">Home</span></button>
  <button class="nav-dest" data-tab="users"><span class="nav-pill"><span class="nav-ic" data-icon="users"></span></span><span class="nav-lbl">Users</span></button>
  <button class="nav-dest" data-tab="buckets"><span class="nav-pill"><span class="nav-ic" data-icon="hard-drive"></span></span><span class="nav-lbl">Buckets</span></button>
  <button class="nav-dest" data-tab="logs"><span class="nav-pill"><span class="nav-ic" data-icon="file-text"></span></span><span class="nav-lbl">Logs</span></button>
  <button class="nav-dest" data-tab="trash"><span class="nav-pill"><span class="nav-ic" data-icon="trash"></span></span><span class="nav-lbl">Trash</span></button>
  <button class="nav-dest" data-tab="settings"><span class="nav-pill"><span class="nav-ic" data-icon="sliders"></span></span><span class="nav-lbl">Settings</span></button>
</nav>

<div id="modalOverlay" class="hidden"><div id="modalBox"></div></div>
<div id="loadingOverlay" class="hidden"><div class="spinner"></div><span class="lbl">Loading&hellip;</span></div>
<div id="toast" class="hidden" role="status"></div>

<script>
"use strict";
const state = { csrf: '', users: [], userId: 0, bucketId: 0, prefix: '', buckets: [], objects: [],
    objTotal: 0, objPage: 1, objPages: 1, objPerPage: 100, objQ: '', objSort: 'name', objOrder: 'asc', sel: new Set(), folders: [],
    objView: 'list', userSort: { col: 'username', dir: 'asc' }, bucketSort: { col: 'name', dir: 'asc' },
    logs: [], logTotal: 0, logPage: 1, logPages: 1, logPerPage: 100,
    trash: [], trashTotal: 0, trashPage: 1, trashPages: 1, trashPerPage: 50, trashEnabled: true,
    totp: false, version: '' };

const $ = (sel, el) => (el || document).querySelector(sel);
const $$ = (sel, el) => Array.from((el || document).querySelectorAll(sel));
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const maskKey = s => s ? String(s).slice(0, 4) + '...' + String(s).slice(-4) : '';

/* ---------- passkeys (WebAuthn) helpers ---------- */
function passkeySupported() {
    return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
}
function bufToB64url(buf) {
    const bytes = new Uint8Array(buf);
    let s = '';
    for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function b64urlToU8(s) {
    s = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    const bin = atob(s);
    const u = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
    return u;
}
async function loginWithPasskey() {
    const btn = $('#passkeyLoginBtn');
    const err = $('#loginError');
    err.classList.add('hidden');
    btn.disabled = true;
    try {
        const d = await api('passkey_challenge', { csrf: false });
        const cred = await navigator.credentials.get({
            publicKey: {
                challenge: b64urlToU8(d.challenge),
                rpId: d.rp_id,
                userVerification: 'preferred',
                allowCredentials: []
            }
        });
        const resp = cred.response;
        const payload = await api('passkey_login', { csrf: false, json: {
            id: cred.id,
            client_data_json: bufToB64url(resp.clientDataJSON),
            authenticator_data: bufToB64url(resp.authenticatorData),
            signature: bufToB64url(resp.signature),
            user_handle: resp.userHandle ? bufToB64url(resp.userHandle) : null
        }});
        state.csrf = payload.csrf;
        $('#loginPassword').value = '';
        showApp(payload.username, payload);
        applyLogSettings(payload.log_s3, payload.log_admin);
    } catch (e) {
        if (e.name === 'NotAllowedError' || e.name === 'AbortError' || e.name === 'NotSupportedError') return;
        err.textContent = (e.data && e.data.error) || e.message || 'Passkey sign-in failed';
        err.classList.remove('hidden');
    } finally {
        btn.disabled = false;
    }
}
$('#passkeyLoginBtn').addEventListener('click', loginWithPasskey);

/* ---------- inline icon set (stroke style, 24px grid) ---------- */
const ICONS = {
    'bucket-logo': '<path d="M5 7h14l2 12H3z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/><path d="M3 19h18"/>',
    'grid': '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'users': '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'user': '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'user-plus': '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/>',
    'key': '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    'hard-drive': '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
    'database': '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
    'box': '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
    'activity': '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'zap': '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    'file-text': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    'file': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'file-plus': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>',
    'file-code': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="10 13 8 15 10 17"/><polyline points="14 13 16 15 14 17"/>',
    'folder': '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    'folder-plus': '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>',
    'image': '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    'film': '<rect x="2" y="2" width="20" height="20" rx="2.5"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/>',
    'music': '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
    'archive': '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
    'upload': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    'download': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'trash': '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
    'edit': '<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>',
    'rename': '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    'copy': '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    'corner-up-right': '<polyline points="15 14 20 9 15 4"/><path d="M4 20v-7a4 4 0 0 1 4-4h12"/>',
    'search': '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'close': '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    'check': '<polyline points="20 6 9 17 4 12"/>',
    'chevron-down': '<polyline points="6 9 12 15 18 9"/>',
    'chevron-left': '<polyline points="15 18 9 12 15 6"/>',
    'chevron-right': '<polyline points="9 18 15 12 9 6"/>',
    'arrow-left': '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
    'arrow-up': '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>',
    'plus': '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    'refresh': '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
    'moon': '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    'sun': '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
    'log-out': '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    'sliders': '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
    'eye': '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    'info': '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
    'more': '<circle cx="12" cy="5" r="1.9" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.9" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.9" fill="currentColor" stroke="none"/>',
    'share': '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
    'list': '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'alert': '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'clock': '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'
};
function icon(name, size) {
    const s = size || 24;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" ' +
        'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        (ICONS[name] || ICONS.info) + '</svg>';
}
function injectIcons(root) {
    $$('[data-icon]', root).forEach(el => { el.innerHTML = icon(el.dataset.icon); });
}

/* ---------- ripple effect ---------- */
document.addEventListener('pointerdown', e => {
    const t = e.target.closest('.btn, .icon-btn, .nav-dest, .csel-item, .fp-item, .fab, .link, .crumb');
    if (!t || t.disabled) return;
    const r = t.getBoundingClientRect();
    const d = Math.max(r.width, r.height) * 1.6;
    const sp = document.createElement('span');
    sp.className = 'ripple';
    sp.style.width = sp.style.height = d + 'px';
    sp.style.left = (e.clientX - r.left - d / 2) + 'px';
    sp.style.top = (e.clientY - r.top - d / 2) + 'px';
    t.appendChild(sp);
    setTimeout(() => sp.remove(), 520);
}, { passive: true });

/* ---------- loading overlay (appears after 250ms of pending requests) ---------- */
const loading = { count: 0, timer: null, shown: false };
function loadingStart() {
    loading.count++;
    if (loading.timer === null) {
        loading.timer = setTimeout(() => {
            loading.timer = null;
            if (loading.count > 0 && !loading.shown) {
                loading.shown = true;
                $('#loadingOverlay').classList.remove('hidden');
            }
        }, 250);
    }
}
function loadingEnd() {
    loading.count = Math.max(0, loading.count - 1);
    if (loading.count === 0) {
        if (loading.timer !== null) { clearTimeout(loading.timer); loading.timer = null; }
        if (loading.shown) {
            loading.shown = false;
            $('#loadingOverlay').classList.add('hidden');
        }
    }
}

async function api(action, opts) {
    opts = opts || {};
    const headers = {};
    if (state.csrf && opts.csrf !== false) headers['X-CSRF-Token'] = state.csrf;
    let url = 'api.php?action=' + encodeURIComponent(action);
    if (opts.params) url += '&' + new URLSearchParams(opts.params);
    let body;
    if (opts.form) body = opts.form;
    else if (opts.json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(opts.json); }
    loadingStart();
    document.body.classList.add('busy');
    let res;
    try {
        res = await fetch(url, { method: opts.method || 'POST', headers, body });
    } catch (e) {
        loadingEnd();
        document.body.classList.remove('busy');
        throw new Error('Network error: ' + e.message);
    }
    loadingEnd();
    document.body.classList.remove('busy');
    let data = null;
    try { data = await res.json(); } catch (e) {}
    if (res.status === 401 && action !== 'login') {
        state.csrf = '';
        showLogin();
    }
    if (!res.ok || !data || data.ok !== true) {
        const err = new Error((data && data.error) || ('HTTP ' + res.status));
        err.data = data || null;
        throw err;
    }
    return data.data;
}

/* ---------- snackbar ---------- */
let toastTimer = null;
function toast(msg, type) {
    const t = $('#toast');
    t.innerHTML = (type === 'ok' ? '<span class="t-ic">' + icon('check', 18) + '</span>'
        : type === 'err' ? '<span class="t-ic">' + icon('alert', 18) + '</span>' : '') + '<span>' + esc(msg) + '</span>';
    t.className = type === 'ok' ? 'toast-ok' : (type === 'err' ? 'toast-err' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.className = 'hidden'; }, 3500);
}
$('#toast').addEventListener('click', () => { clearTimeout(toastTimer); $('#toast').className = 'hidden'; });

/* ---------- dialogs ---------- */
function openModal(html) {
    $('#modalBox').innerHTML = html;
    injectIcons($('#modalBox'));
    $('#modalOverlay').classList.remove('hidden');
}
function closeModal() {
    $('#modalOverlay').classList.add('hidden');
    $('#modalBox').classList.remove('wide');
}
$('#modalOverlay').addEventListener('click', e => {
    if (e.target === $('#modalOverlay') || e.target.closest('[data-close]')) closeModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !$('#modalOverlay').classList.contains('hidden')) closeModal();
});

/* ---------- formatters ---------- */
const nf1 = new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 });
function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n.toLocaleString() + ' B';
    const u = ['KB', 'MB', 'GB', 'TB'];
    let i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
    return nf1.format(n) + ' ' + u[i];
}
function fmtSpeed(bytesPerSec) {
    bytesPerSec = Number(bytesPerSec) || 0;
    if (bytesPerSec <= 0) return '0 B/s';
    if (bytesPerSec < 1024) return Math.round(bytesPerSec).toLocaleString() + ' B/s';
    const u = ['KB/s', 'MB/s', 'GB/s'];
    let i = -1, v = bytesPerSec;
    do { v /= 1024; i++; } while (v >= 1024 && i < u.length - 1);
    return nf1.format(v) + ' ' + u[i];
}
function fmtEta(sec) {
    sec = Number(sec) || 0;
    if (sec < 1) return '';
    if (sec < 60) return Math.ceil(sec) + ' s';
    const m = Math.floor(sec / 60);
    return m + 'm ' + Math.round(sec % 60) + 's';
}
/* ---------- formatters (locale aware; timestamps are stored in UTC) ---------- */
const tsToDate = ts => new Date(String(ts).replace(' ', 'T') + 'Z');
const dtfMedium = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
function fmtTime(ts) {
    if (!ts) return '';
    const d = tsToDate(ts);
    if (isNaN(d.getTime())) return ts;
    return dtfMedium.format(d);
}
function fmtRel(ts) {
    if (!ts) return '';
    const d = tsToDate(ts);
    if (isNaN(d.getTime())) return ts;
    const sec = Math.round((Date.now() - d.getTime()) / 1000);
    if (sec < 45) return 'just now';
    if (sec < 3600) return Math.round(sec / 60) + ' min ago';
    if (sec < 86400) return Math.round(sec / 3600) + ' h ago';
    if (sec < 30 * 86400) return Math.round(sec / 86400) + ' d ago';
    return fmtTime(ts);
}
function copyText(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => toast('Copied to clipboard', 'ok')).catch(() => toast('Copy failed', 'err'));
    } else {
        toast('Clipboard not available', 'err');
    }
}

/* ---------- theme ---------- */
function applyTheme(dark) {
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    const b = $('#themeBtn');
    b.innerHTML = icon(dark ? 'sun' : 'moon');
    b.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
    try { localStorage.setItem('minis3_theme', dark ? 'dark' : 'light'); } catch (e) {}
}
// Theme: use the saved choice, otherwise follow the system preference.
let savedTheme = null;
try { savedTheme = localStorage.getItem('minis3_theme'); } catch (e) {}
if (savedTheme !== 'light' && savedTheme !== 'dark') {
    savedTheme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}
applyTheme(savedTheme === 'dark');
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
    let saved = null;
    try { saved = localStorage.getItem('minis3_theme'); } catch (err) {}
    if (saved !== 'light' && saved !== 'dark') applyTheme(e.matches);
});
$('#themeBtn').addEventListener('click', () => applyTheme((document.documentElement.dataset.theme || 'light') !== 'dark'));

/* ---------- custom dropdown (M3 menu style) ---------- */
const cselRenders = {};
function upgradeSelect(id) {
    const sel = $('#' + id);
    if (!sel) return;
    const wrap = document.createElement('div');
    wrap.className = 'csel';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'csel-btn';
    const lbl = document.createElement('span');
    lbl.style.overflow = 'hidden';
    lbl.style.textOverflow = 'ellipsis';
    const caret = document.createElement('span');
    caret.className = 'caret';
    caret.innerHTML = icon('chevron-down', 18);
    const list = document.createElement('div');
    list.className = 'csel-list hidden';
    const render = () => {
        const o = sel.options[sel.selectedIndex];
        lbl.textContent = o ? o.text : (sel.options[0] ? sel.options[0].text : '');
    };
    const build = () => {
        list.innerHTML = '';
        [...sel.options].forEach((o, i) => {
            const d = document.createElement('div');
            d.className = 'csel-item' + (i === sel.selectedIndex ? ' sel' : '');
            d.innerHTML = '<span class="chk">' + icon('check', 16) + '</span>';
            const t = document.createElement('span');
            t.style.overflow = 'hidden';
            t.style.textOverflow = 'ellipsis';
            t.textContent = o.text;
            d.appendChild(t);
            d.onclick = ev => {
                ev.stopPropagation();
                sel.selectedIndex = i;
                sel.dispatchEvent(new Event('change'));
                list.classList.add('hidden');
                wrap.classList.remove('open');
                render();
            };
            list.appendChild(d);
        });
    };
    btn.onclick = e => {
        e.stopPropagation();
        const wasHidden = list.classList.contains('hidden');
        $$('.csel.open').forEach(w => { w.classList.remove('open'); w.querySelector('.csel-list').classList.add('hidden'); });
        if (wasHidden) {
            build();
            list.classList.remove('hidden');
            wrap.classList.add('open');
        }
    };
    document.addEventListener('click', e => {
        if (!e.target.closest('.csel')) {
            list.classList.add('hidden');
            wrap.classList.remove('open');
        }
    });
    btn.appendChild(lbl);
    btn.appendChild(caret);
    wrap.appendChild(btn);
    wrap.appendChild(list);
    sel.classList.add('hidden');
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    render();
    cselRenders[id] = render;
}
['bucketUserSelect', 'logUser', 'logKind', 'logMethod', 'logStatus'].forEach(upgradeSelect);

/* ---------- custom confirm dialog ---------- */
function confirmDialog(msg, yesLabel, onYes) {
    const danger = yesLabel === 'Delete';
    openModal(
        '<h3>Please confirm</h3>' +
        '<p>' + esc(msg) + '</p>' +
        '<div class="modal-actions">' +
        '<button id="confirmYesBtn" class="btn ' + (danger ? 'btn-danger-filled' : 'btn-filled') + '">' + esc(yesLabel || 'OK') + '</button>' +
        '<button class="btn btn-text" data-close>Cancel</button>' +
        '</div>');
    $('#confirmYesBtn').onclick = () => { closeModal(); onYes(); };
}

/* ---------- auth ---------- */
async function boot() {
    injectIcons(document);
    try {
        const d = await api('me');
        state.csrf = d.csrf;
        showApp(d.username, d);
        applyLogSettings(d.log_s3, d.log_admin);
    } catch (e) {
        showLogin();
    }
}

function showApp(uname, opts) {
    opts = opts || {};
    state.totp = !!opts.totp;
    if (opts.version) {
        state.version = opts.version;
        $('#appVersion').textContent = opts.version;
    }
    if (opts.trash_days !== undefined) {
        state.trashEnabled = Number(opts.trash_days) > 0;
        $('#trashDays').value = Number(opts.trash_days);
    }
    if (opts.app_name !== undefined) {
        $('#appNameInput').value = opts.app_name;
    }
    $('#loginBox').classList.add('hidden');
    $('#appBox').classList.remove('hidden');
    $('#navRail').classList.remove('hidden');
    $('#bottomNav').classList.remove('hidden');
    $('#logoutBtn').classList.remove('hidden');
    const chip = $('#headerUser');
    chip.innerHTML = '<span class="chip-avatar">' + esc((uname || 'a').charAt(0).toUpperCase()) + '</span><span>' + esc(uname || 'admin') + '</span>';
    chip.classList.remove('hidden');
    $('#profileUsername').value = uname || 'admin';
    loadUsers();
    activateTab(initialTab(), false);
}

function applyLogSettings(logS3, logAdmin) {
    state.logS3 = !!logS3;
    state.logAdmin = !!logAdmin;
    $('#logS3').checked = state.logS3;
    $('#logAdmin').checked = state.logAdmin;
    const hints = [];
    if (!state.logS3) hints.push('S3 API logging is off');
    if (!state.logAdmin) hints.push('admin logging is off');
    const h = $('#logHint');
    h.textContent = hints.join(' - ');
    h.classList.toggle('hidden', !hints.length);
}

function showLogin() {
    $('#loginBox').classList.remove('hidden');
    $('#appBox').classList.add('hidden');
    $('#navRail').classList.add('hidden');
    $('#bottomNav').classList.add('hidden');
    $('#logoutBtn').classList.add('hidden');
    $('#headerUser').classList.add('hidden');
    $('#headerUser').textContent = '';
    $('#passkeyLoginBtn').classList.toggle('hidden', !passkeySupported());
}

$('#loginForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const err = $('#loginError');
    err.classList.add('hidden');
    try {
        const body = { username: $('#loginUsername').value, password: $('#loginPassword').value };
        if (!$('#loginCodeWrap').classList.contains('hidden')) body.code = $('#loginCode').value;
        const d = await api('login', { form: new URLSearchParams(body) });
        state.csrf = d.csrf;
        $('#loginPassword').value = '';
        $('#loginCode').value = '';
        $('#loginCodeWrap').classList.add('hidden');
        showApp(d.username, d);
        applyLogSettings(d.log_s3, d.log_admin);
    } catch (e) {
        if (e.data && e.data.totp) {
            $('#loginCodeWrap').classList.remove('hidden');
            $('#loginCode').focus();
        }
        err.textContent = e.message;
        err.classList.remove('hidden');
    }
});

$('#logoutBtn').addEventListener('click', async () => {
    try { await api('logout', { csrf: false }); } catch (e) {}
    state.csrf = '';
    clearFilesView();
    showLogin();
});

/* ---------- navigation (rail + bottom nav, URL-hash routed) ---------- */
const VALID_TABS = ['stats', 'users', 'buckets', 'logs', 'trash', 'settings'];
let filesViewRestored = false;

function tabFromHash() {
    const h = (location.hash || '').replace(/^#/, '');
    return VALID_TABS.indexOf(h) >= 0 ? h : 'stats';
}

// Where to land on load: the URL hash wins; on a plain refresh with no hash,
// fall back to the last visited tab (refresh normally preserves the hash,
// this covers cases where it was lost).
function initialTab() {
    const h = (location.hash || '').replace(/^#/, '');
    if (VALID_TABS.indexOf(h) >= 0) return h;
    try {
        const nav = performance.getEntriesByType('navigation')[0];
        if (nav && nav.type === 'reload') {
            const saved = localStorage.getItem('minis3_tab');
            if (VALID_TABS.indexOf(saved) >= 0) return saved;
        }
    } catch (e) {}
    return 'stats';
}

function activateTab(tab, updateHash) {
    if (VALID_TABS.indexOf(tab) < 0) tab = 'stats';
    $$('.nav-dest').forEach(x => x.classList.toggle('active', x.dataset.tab === tab));
    $$('.tab-panel').forEach(s => s.classList.add('hidden'));
    $('#tab-' + tab).classList.remove('hidden');
    try { localStorage.setItem('minis3_tab', tab); } catch (e) {}
    if (updateHash !== false && location.hash !== '#' + tab) {
        location.hash = tab;
    }
    if (tab === 'stats') loadStats();
    if (tab === 'users') loadUsers();
    if (tab === 'buckets') loadBuckets().then(maybeRestoreFilesView);
    if (tab === 'logs') loadLogs();
    if (tab === 'trash') loadTrash();
    if (tab === 'settings') { renderTotpStatus(); loadUploadsPanel(); loadPasskeys(); }
}

// Back / forward buttons and manual hash edits switch tabs too.
window.addEventListener('hashchange', () => {
    if ($('#appBox').classList.contains('hidden')) return;
    const t = tabFromHash();
    if (!$('#tab-' + t).classList.contains('hidden')) return;
    activateTab(t, false);
});

$$('.nav-dest').forEach(b => b.addEventListener('click', () => activateTab(b.dataset.tab)));

/* Remember the opened bucket + folder so a refresh returns where you were. */
function saveFilesView() {
    try {
        const inFiles = state.bucketId && !$('#filesView').classList.contains('hidden');
        localStorage.setItem('minis3_files', JSON.stringify(inFiles ? { bucketId: state.bucketId, prefix: state.prefix } : null));
    } catch (e) {}
}
function clearFilesView() {
    try { localStorage.removeItem('minis3_files'); } catch (e) {}
}
function maybeRestoreFilesView() {
    if (filesViewRestored) return;
    filesViewRestored = true;
    let v = null;
    try { v = JSON.parse(localStorage.getItem('minis3_files') || 'null'); } catch (e) {}
    if (!v || !v.bucketId || !state.buckets.some(b => Number(b.id) === Number(v.bucketId))) return;
    openBucket(Number(v.bucketId), String(v.prefix || ''));
}

/* ---------- dashboard stats ---------- */
async function loadStats() {
    let d;
    try {
        d = await api('stats', { method: 'GET' });
    } catch (err) { toast(err.message, 'err'); return; }
    $('#stUsers').textContent = d.users;
    $('#stBuckets').textContent = d.buckets;
    $('#stObjects').textContent = d.objects;
    $('#stSize').textContent = fmtBytes(d.size);
    $('#stRequests').textContent = d.requests.toLocaleString();
    $('#stAvgMs').textContent = d.avgMs.toLocaleString() + ' ms';

    const total = d.req2xx + d.req4xx + d.req5xx || 1;
    $('#bar2xx').style.width = (d.req2xx / total * 100).toFixed(1) + '%';
    $('#bar4xx').style.width = (d.req4xx / total * 100).toFixed(1) + '%';
    $('#bar5xx').style.width = (d.req5xx / total * 100).toFixed(1) + '%';
    $('#lbl2xx').textContent = d.req2xx;
    $('#lbl4xx').textContent = d.req4xx;
    $('#lbl5xx').textContent = d.req5xx;

    const chart = $('#chart24');
    chart.innerHTML = '';
    if (!d.h24.length) {
        chart.innerHTML = '<div class="empty">' + icon('activity') + '<span>No requests in the last 24 hours.</span></div>';
    } else {
        const hourFmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric' });
        const tipFmt = new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeStyle: 'short' });
        const reqFmt = new Intl.NumberFormat(undefined);
        const max = Math.max(1, ...d.h24.map(h => h[1]));
        d.h24.forEach((h, i) => {
            const col = document.createElement('div');
            col.className = 'col';
            const bar = document.createElement('div');
            bar.className = 'bar';
            bar.style.height = Math.round(h[1] / max * 100) + '%';
            bar.title = tipFmt.format(tsToDate(h[0])) + ' (' + reqFmt.format(h[1]) + ' request' + (h[1] === 1 ? '' : 's') + ')';
            col.appendChild(bar);
            if (i % 4 === 0) {
                const lab = document.createElement('span');
                lab.className = 'h';
                lab.textContent = hourFmt.format(tsToDate(h[0]));
                col.appendChild(lab);
            }
            chart.appendChild(col);
        });
    }

    const tu = $('#topUsers');
    tu.innerHTML = '';
    if (!d.topUsers.length) {
        tu.innerHTML = '<div class="empty">' + icon('users') + '<span>No activity yet.</span></div>';
    } else {
        const maxC = Math.max(1, ...d.topUsers.map(u => u.count));
        d.topUsers.forEach(u => {
            tu.insertAdjacentHTML('beforeend',
                '<div class="tu-row"><span>' + esc(u.username) + '</span><div class="tu-bar"><div style="width:' + Math.round(u.count / maxC * 100) + '%"></div></div><span class="muted">' + u.count.toLocaleString() + '</span></div>');
        });
    }

    const ra = $('#recentActivity');
    ra.innerHTML = '';
    if (!d.recent.length) {
        ra.innerHTML = '<div class="empty">' + icon('clock') + '<span>No activity yet.</span></div>';
    } else {
        d.recent.forEach(r => {
            const sClass = r.status >= 500 ? 'st5' : (r.status >= 400 ? 'st4' : 'st2');
            ra.insertAdjacentHTML('beforeend',
                '<div class="ra-row"><span class="st ' + sClass + '">' + Number(r.status) + '</span>' +
                '<code>' + esc(r.method || '') + '</code>' +
                '<span class="ra-uri" title="' + esc(r.uri || '') + '">' + esc(r.uri || '') + '</span>' +
                '<span class="muted">' + esc(r.username || (r.kind === 'admin' ? 'admin' : '-')) + '</span></div>');
        });
    }
}

$('#refreshStatsBtn').addEventListener('click', loadStats);

/* ---------- users ---------- */
async function loadUsers() {
    try {
        const rows = await api('users', { method: 'GET' });
        state.users = rows;
        renderUsers();
        populateUserSelects();
    } catch (err) { toast(err.message, 'err'); }
}

function populateUserSelects() {
    const opts = '<option value="">All users</option>' + state.users.map(u => '<option value="' + u.id + '">' + esc(u.username) + '</option>').join('');
    $('#bucketUserSelect').innerHTML = opts;
    $('#bucketUserSelect').value = state.userId || '';
    $('#logUser').innerHTML = opts;
    if (cselRenders['bucketUserSelect']) cselRenders['bucketUserSelect']();
    if (cselRenders['logUser']) cselRenders['logUser']();
}

function sparkline(counts, w, h) {
    const max = Math.max(1, ...counts);
    const pts = counts.map((c, i) =>
        (i / (counts.length - 1) * (w - 2) + 1).toFixed(1) + ',' + (h - 2 - (c / max) * (h - 4)).toFixed(1)
    ).join(' ');
    return '<svg class="spark" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true">' +
        '<polyline points="' + pts + '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

function sortRows(rows, sortState, cols) {
    const col = cols[sortState.col] ? sortState.col : Object.keys(cols)[0];
    const dir = sortState.dir === 'desc' ? -1 : 1;
    return [...rows].sort((a, b) => {
        const av = cols[col](a), bv = cols[col](b);
        if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir;
        return String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' }) * dir;
    });
}

function applySortMarks(section, sortState) {
    $$(section + ' .sortbtn').forEach(b => {
        b.closest('th').classList.toggle('sorted', b.dataset.usort === sortState.col || b.dataset.bsort === sortState.col);
        const m = b.querySelector('.sortmark');
        const active = b.dataset.usort === sortState.col || b.dataset.bsort === sortState.col;
        m.textContent = active ? (sortState.dir === 'asc' ? '\u2191' : '\u2193') : '';
    });
}

const USER_SORT_COLS = {
    username: u => u.username,
    storage_used: u => Number(u.storage_used || 0),
    created_at: u => String(u.created_at || ''),
};

function renderUsers() {
    const tb = $('#usersTbody');
    tb.innerHTML = '';
    applySortMarks('#tab-users', state.userSort);
    if (!state.users.length) {
        tb.innerHTML = '<tr><td colspan="6"><div class="empty">' + icon('users') + '<span>No users yet.</span></div></td></tr>';
        return;
    }
    for (const u of sortRows(state.users, state.userSort, USER_SORT_COLS)) {
        const tr = document.createElement('tr');
        const quota = Number(u.quota_bytes || 0);
        const used = Number(u.storage_used || 0);
        const meta = Number(u.object_count || 0).toLocaleString() + ' object' + (Number(u.object_count) === 1 ? '' : 's')
            + ' in ' + Number(u.bucket_count || 0).toLocaleString() + ' bucket' + (Number(u.bucket_count) === 1 ? '' : 's')
            + (quota > 0 ? ' · ' + fmtBytes(used) + ' of ' + fmtBytes(quota) + ' used' : '');
        let storageHtml = '<span class="cellname"><span class="ficon">' + icon('database', 18) + '</span><span class="nm">' + esc(fmtBytes(used)) + '</span></span>';
        if (quota > 0) {
            const pct = Math.min(100, Math.round(used / quota * 100));
            storageHtml += '<div class="quota-bar' + (pct >= 100 ? ' over' : '') + '" title="' + pct + '% of quota"><div style="width:' + pct + '%"></div></div>';
        }
        if (u.usage14 && u.usage14.some(c => c > 0)) {
            storageHtml += '<div title="S3 requests, last 14 days">' + sparkline(u.usage14, 64, 18) + '</div>';
        }
        tr.innerHTML =
            '<td><span class="cellname"><span class="ficon" style="color:var(--primary)">' + icon('user', 20) + '</span><strong class="nm">' + esc(u.username) + '</strong></span></td>' +
            '<td><code>' + esc(u.access_key) + '</code></td>' +
            '<td><code>' + esc(maskKey(u.secret_key)) + '</code></td>' +
            '<td title="' + esc(meta) + '">' + storageHtml + '</td>' +
            '<td class="muted" title="' + esc(fmtTime(u.created_at)) + '">' + esc(fmtRel(u.created_at)) + '</td>' +
            '<td class="actions">' +
            '<button data-act="show" data-id="' + u.id + '" class="btn btn-tonal btn-sm"><span class="bi">' + icon('key', 16) + '</span>Keys</button>' +
            '<button data-act="edit" data-id="' + u.id + '" class="btn btn-outlined btn-sm"><span class="bi">' + icon('edit', 16) + '</span>Edit</button>' +
            '<button data-act="delete" data-id="' + u.id + '" class="btn btn-danger btn-sm"><span class="bi">' + icon('trash', 16) + '</span>Delete</button>' +
            '</td>';
        tb.appendChild(tr);
    }
}

$('#usersTbody').addEventListener('click', e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    const id = Number(btn.dataset.id);
    if (btn.dataset.act === 'show') showKeys(id);
    if (btn.dataset.act === 'edit') openEditUser(id);
    if (btn.dataset.act === 'delete') deleteUser(id);
});

$$('#tab-users .sortbtn').forEach(b => b.addEventListener('click', () => {
    const c = b.dataset.usort;
    if (state.userSort.col === c) {
        state.userSort.dir = state.userSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
        state.userSort = { col: c, dir: 'asc' };
    }
    renderUsers();
}));

function showKeys(idOrUser) {
    const u = typeof idOrUser === 'object' ? idOrUser : state.users.find(x => x.id === idOrUser);
    if (!u) return;
    openModal(
        '<h3>Keys for ' + esc(u.username) + '</h3>' +
        '<div class="tf"><input readonly value="' + esc(u.access_key) + '" placeholder=" "><label>Access key</label></div>' +
        '<div class="tf"><input readonly value="' + esc(u.secret_key) + '" placeholder=" "><label>Secret key</label></div>' +
        '<div class="modal-actions">' +
        '<button id="copyBothBtn" class="btn btn-filled"><span class="bi">' + icon('copy', 16) + '</span>Copy both</button>' +
        '<button class="btn btn-text" data-close>Done</button>' +
        '</div>');
    $('#copyBothBtn').onclick = () => copyText(u.access_key + '\n' + u.secret_key);
}

$('#addUserBtn').addEventListener('click', () => {
    openModal(
        '<h3>Add user</h3>' +
        '<form id="userForm">' +
        '<div class="tf"><input name="username" required pattern="[A-Za-z0-9._\\-]{1,64}" autocomplete="off" placeholder=" "><label>Username</label></div>' +
        '<div class="tf"><input name="access_key" placeholder=" " autocomplete="off"><label>Access key (optional)</label></div>' +
        '<div class="tf"><input name="secret_key" placeholder=" " autocomplete="off"><label>Secret key (optional)</label></div>' +
        '<div class="tf"><input name="quota_mb" type="number" min="0" step="1" placeholder=" " autocomplete="off"><label>Storage quota in MB (0 = unlimited)</label></div>' +
        '<div class="modal-actions"><button type="submit" class="btn btn-filled">Create</button><button type="button" class="btn btn-text" data-close>Cancel</button></div>' +
        '</form>');
    $('#userForm').onsubmit = async ev => {
        ev.preventDefault();
        const fd = new FormData($('#userForm'));
        fd.append('_sub', 'create');
        try {
            const u = await api('users', { form: fd });
            closeModal();
            await loadUsers();
            showKeys(u);
        } catch (err) { toast(err.message, 'err'); }
    };
});

function openEditUser(id) {
    const u = state.users.find(x => x.id === id);
    if (!u) return;
    openModal(
        '<h3>Edit user: ' + esc(u.username) + '</h3>' +
        '<form id="userForm">' +
        '<input type="hidden" name="id" value="' + u.id + '">' +
        '<div class="tf"><input name="username" value="' + esc(u.username) + '" required pattern="[A-Za-z0-9._\\-]{1,64}" autocomplete="off" placeholder=" "><label>Username</label></div>' +
        '<div class="tf"><input name="quota_mb" type="number" min="0" step="1" value="' + Math.round(Number(u.quota_bytes || 0) / 1048576) + '" placeholder=" " autocomplete="off"><label>Storage quota in MB (0 = unlimited)</label></div>' +
        '<div class="modal-actions">' +
        '<button type="submit" class="btn btn-filled">Save</button>' +
        '<button type="button" id="regenBtn" class="btn btn-tonal"><span class="bi">' + icon('refresh', 16) + '</span>Regenerate secret key</button>' +
        '<button type="button" class="btn btn-text" data-close>Cancel</button>' +
        '</div>' +
        '</form>');
    $('#userForm').onsubmit = async ev => {
        ev.preventDefault();
        const fd = new FormData($('#userForm'));
        fd.append('_sub', 'update');
        try {
            await api('users', { form: fd });
            closeModal();
            await loadUsers();
            toast('User updated', 'ok');
        } catch (err) { toast(err.message, 'err'); }
    };
    $('#regenBtn').onclick = () => {
        confirmDialog('Regenerate the secret key? Existing clients will stop working until updated.', 'Regenerate', async () => {
            const fd = new FormData($('#userForm'));
            fd.append('_sub', 'update');
            fd.append('regen_secret', '1');
            try {
                const u2 = await api('users', { form: fd });
                closeModal();
                await loadUsers();
                showKeys(u2);
            } catch (err) { toast(err.message, 'err'); }
        });
    };
}

async function deleteUser(id) {
    const u = state.users.find(x => x.id === id);
    if (!u) return;
    confirmDialog('Delete user "' + u.username + '" and ALL their buckets and files? This cannot be undone.', 'Delete', async () => {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('_sub', 'delete');
        try {
            await api('users', { form: fd });
            await loadUsers();
            toast('User deleted', 'ok');
            if (state.userId === id) state.userId = 0;
        } catch (err) { toast(err.message, 'err'); }
    });
}

/* ---------- buckets ---------- */
async function loadBuckets() {
    const params = state.userId ? { user_id: state.userId } : {};
    try {
        const rows = await api('buckets', { method: 'GET', params });
        state.buckets = rows;
        renderBuckets();
    } catch (err) { toast(err.message, 'err'); }
}

const BUCKET_SORT_COLS = {
    name: b => b.name,
    username: b => b.username,
    object_count: b => Number(b.object_count || 0),
    created_at: b => String(b.created_at || ''),
};

function renderBuckets() {
    const tb = $('#bucketsTbody');
    tb.innerHTML = '';
    applySortMarks('#tab-buckets', state.bucketSort);
    if (!state.buckets.length) {
        tb.innerHTML = '<tr><td colspan="5"><div class="empty">' + icon('hard-drive') + '<span>No buckets yet.</span></div></td></tr>';
        return;
    }
    for (const b of sortRows(state.buckets, state.bucketSort, BUCKET_SORT_COLS)) {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><span class="cellname"><span class="ficon" style="color:var(--primary)">' + icon('hard-drive', 20) + '</span><strong class="nm">' + esc(b.name) + '</strong></span></td>' +
            '<td class="muted">' + esc(b.username) + '</td>' +
            '<td>' + Number(b.object_count).toLocaleString() + '</td>' +
            '<td class="muted" title="' + esc(fmtTime(b.created_at)) + '">' + esc(fmtRel(b.created_at)) + '</td>' +
            '<td class="actions">' +
            '<button data-act="open" data-id="' + b.id + '" class="btn btn-tonal btn-sm"><span class="bi">' + icon('folder', 16) + '</span>Open</button>' +
            '<button data-act="rename" data-id="' + b.id + '" class="btn btn-outlined btn-sm"><span class="bi">' + icon('rename', 16) + '</span>Rename</button>' +
            '<button data-act="delete" data-id="' + b.id + '" class="btn btn-danger btn-sm"><span class="bi">' + icon('trash', 16) + '</span>Delete</button>' +
            '</td>';
        tb.appendChild(tr);
    }
}

$('#bucketUserSelect').addEventListener('change', () => {
    state.userId = Number($('#bucketUserSelect').value) || 0;
    loadBuckets();
});

$$('#tab-buckets .sortbtn').forEach(b => b.addEventListener('click', () => {
    const c = b.dataset.bsort;
    if (state.bucketSort.col === c) {
        state.bucketSort.dir = state.bucketSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
        state.bucketSort = { col: c, dir: 'asc' };
    }
    renderBuckets();
}));

$('#addBucketBtn').addEventListener('click', () => {
    const userOpts = state.users.map(u => '<option value="' + u.id + '">' + esc(u.username) + '</option>').join('');
    openModal(
        '<h3>Add bucket</h3>' +
        '<form id="bucketForm">' +
        '<div class="tf float"><select name="user_id" required class="has-value">' + userOpts + '</select><label>User</label><span class="tf-caret">' + icon('chevron-down', 18) + '</span></div>' +
        '<div class="tf"><input name="name" required pattern="[a-z0-9][a-z0-9.\\-]{1,61}[a-z0-9]" placeholder=" " autocomplete="off"><label>Bucket name</label></div>' +
        '<div class="modal-actions"><button type="submit" class="btn btn-filled">Create</button><button type="button" class="btn btn-text" data-close>Cancel</button></div>' +
        '</form>');
    $('#bucketForm').onsubmit = async ev => {
        ev.preventDefault();
        const fd = new FormData($('#bucketForm'));
        fd.append('_sub', 'create');
        try {
            await api('buckets', { form: fd });
            closeModal();
            await loadBuckets();
            toast('Bucket created', 'ok');
        } catch (err) { toast(err.message, 'err'); }
    };
});

$('#bucketsTbody').addEventListener('click', e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    const id = Number(btn.dataset.id);
    if (btn.dataset.act === 'open') openBucket(id);
    if (btn.dataset.act === 'rename') renameBucket(id);
    if (btn.dataset.act === 'delete') deleteBucket(id);
});

function renameBucket(id) {
    const b = state.buckets.find(x => x.id === id);
    if (!b) return;
    openModal(
        '<h3>Rename bucket</h3>' +
        '<form id="bucketForm">' +
        '<input type="hidden" name="id" value="' + b.id + '">' +
        '<div class="tf"><input name="name" value="' + esc(b.name) + '" required pattern="[a-z0-9][a-z0-9.\\-]{1,61}[a-z0-9]" autocomplete="off" placeholder=" "><label>Bucket name</label></div>' +
        '<div class="modal-actions"><button type="submit" class="btn btn-filled">Rename</button><button type="button" class="btn btn-text" data-close>Cancel</button></div>' +
        '</form>');
    $('#bucketForm').onsubmit = async ev => {
        ev.preventDefault();
        const fd = new FormData($('#bucketForm'));
        fd.append('_sub', 'rename');
        try {
            await api('buckets', { form: fd });
            closeModal();
            await loadBuckets();
            toast('Bucket renamed', 'ok');
        } catch (err) { toast(err.message, 'err'); }
    };
}

async function deleteBucket(id) {
    const b = state.buckets.find(x => x.id === id);
    if (!b) return;
    confirmDialog('Delete bucket "' + b.name + '" and ALL files inside it? This cannot be undone.', 'Delete', async () => {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('_sub', 'delete');
        try {
            await api('buckets', { form: fd });
            if (Number(id) === Number(state.bucketId)) clearFilesView();
            await loadBuckets();
            toast('Bucket deleted', 'ok');
        } catch (err) { toast(err.message, 'err'); }
    });
}

function openBucket(id, prefix) {
    state.bucketId = id;
    state.prefix = prefix || '';
    state.objPage = 1;
    state.objQ = '';
    $('#objSearch').value = '';
    clearSel();
    $('#bucketView').classList.add('hidden');
    $('#filesView').classList.remove('hidden');
    saveFilesView();
    loadFiles();
}

$('#backToBucketsBtn').addEventListener('click', () => {
    $('#filesView').classList.add('hidden');
    $('#bucketView').classList.remove('hidden');
    clearFilesView();
    loadBuckets();
});

/* ---------- files ---------- */
async function loadFiles() {
    const params = { bucket_id: state.bucketId, prefix: state.prefix, page: state.objPage, per_page: state.objPerPage, sort: state.objSort, order: state.objOrder };
    if (state.objQ) params.q = state.objQ;
    let d;
    try {
        d = await api('objects', { method: 'GET', params });
    } catch (err) { toast(err.message, 'err'); return; }
    state.objects = d.rows;
    state.objTotal = d.total;
    state.objPage = d.page;
    state.objPages = d.pages;
    state.objPerPage = d.per_page;
    renderFiles();
}

function groupObjects(objs, prefix) {
    const folders = new Set();
    const files = [];
    for (const o of objs) {
        const rest = o.key.slice(prefix.length);
        const idx = rest.indexOf('/');
        if (idx >= 0) folders.add(rest.slice(0, idx + 1));
        else files.push(o);
    }
    return { folders: [...folders].sort(), files };
}

function fileExt(key) {
    const base = String(key).slice(String(key).lastIndexOf('/') + 1);
    const di = base.lastIndexOf('.');
    return di > 0 ? base.slice(di + 1).toLowerCase() : '\u2014';
}

function fileIcon(key) {
    if (!key || key.endsWith('/')) return '<span class="ficon folder">' + icon('folder', 20) + '</span>';
    const ext = fileExt(key);
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif'].includes(ext)) return '<span class="ficon">' + icon('image', 20) + '</span>';
    if (['mp4', 'webm', 'ogv', 'm4v', 'mov', 'mkv', 'avi'].includes(ext)) return '<span class="ficon">' + icon('film', 20) + '</span>';
    if (['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus'].includes(ext)) return '<span class="ficon">' + icon('music', 20) + '</span>';
    if (['zip', 'gz', 'tar', 'rar', '7z', 'bz2', 'xz'].includes(ext)) return '<span class="ficon">' + icon('archive', 20) + '</span>';
    if (['js', 'mjs', 'php', 'py', 'rb', 'pl', 'sh', 'json', 'xml', 'yml', 'yaml', 'toml', 'html', 'htm', 'css', 'sql'].includes(ext)) return '<span class="ficon">' + icon('file-code', 20) + '</span>';
    if (['txt', 'md', 'log', 'conf', 'cfg', 'ini', 'csv', 'tsv', 'srt', 'vtt'].includes(ext)) return '<span class="ficon">' + icon('file-text', 20) + '</span>';
    return '<span class="ficon">' + icon('file', 20) + '</span>';
}

document.querySelectorAll('#filesView .sortbtn').forEach(b => {
    b.addEventListener('click', () => {
        const s = b.dataset.sort;
        if (state.objSort === s) {
            state.objOrder = state.objOrder === 'asc' ? 'desc' : 'asc';
        } else {
            state.objSort = s;
            state.objOrder = 'asc';
        }
        state.objPage = 1;
        loadFiles();
    });
});

function renderPager(contId, page, pages, perPage, onPage, onPerPage) {
    const c = $('#' + contId);
    c.innerHTML =
        '<button data-pg="prev" class="btn btn-tonal btn-sm"' + (page <= 1 ? ' disabled' : '') + '><span class="bi">' + icon('chevron-left', 16) + '</span>Prev</button>' +
        '<span class="pageinfo">Page ' + page + ' / ' + pages + '</span>' +
        '<button data-pg="next" class="btn btn-tonal btn-sm"' + (page >= pages ? ' disabled' : '') + '>Next<span class="bi">' + icon('chevron-right', 16) + '</span></button>' +
        '<select data-per aria-label="Rows per page">' + [50, 100, 250].map(n => '<option value="' + n + '"' + (n === perPage ? ' selected' : '') + '>' + n + '/page</option>').join('') + '</select>';
    c.querySelector('[data-pg="prev"]').onclick = () => { if (page > 1) onPage(page - 1); };
    c.querySelector('[data-pg="next"]').onclick = () => { if (page < pages) onPage(page + 1); };
    c.querySelector('[data-per]').onchange = e => onPerPage(Number(e.target.value));
}

function renderFiles() {
    const b = state.buckets.find(x => x.id === state.bucketId);
    const u = b ? state.users.find(x => x.id === b.user_id) : null;
    $('#filesTitle').textContent = b ? (u ? u.username + ' / ' : '') + b.name : '';
    $('#filesMeta').textContent = state.objTotal.toLocaleString() + ' object(s)';

    const parts = state.prefix ? state.prefix.replace(/\/$/, '').split('/') : [];
    let pathbar = '<button class="crumb" data-prefix="">' + icon('hard-drive', 14) + ' root</button>';
    let acc = '';
    parts.forEach(p => {
        acc += p + '/';
        pathbar += '<span class="crumb-sep">/</span><button class="crumb" data-prefix="' + esc(acc) + '">' + esc(p) + '</button>';
    });
    $('#pathbar').innerHTML = pathbar;

    const { folders, files } = groupObjects(state.objects, state.prefix);
    const tb = $('#filesTbody');
    tb.innerHTML = '';
    document.querySelectorAll('#filesView th').forEach(th => th.classList.toggle('sorted', th.querySelector('.sortbtn') && th.querySelector('.sortbtn').dataset.sort === state.objSort));
    document.querySelectorAll('#filesView .sortmark').forEach(m => {
        const btn = m.closest('.sortbtn');
        m.textContent = btn && btn.dataset.sort === state.objSort ? (state.objOrder === 'asc' ? '\u2191' : '\u2193') : '';
    });
    const visibleKeys = [];
    const inGrid = state.objView === 'grid';
    $('#filesTableWrap').classList.toggle('hidden', inGrid);
    $('#fileGrid').classList.toggle('hidden', !inGrid);
    if (!inGrid) {
        if (state.prefix) {
            tb.insertAdjacentHTML('beforeend', '<tr class="dir"><td colspan="6"><button class="link" data-up="1"><span style="display:inline-flex;vertical-align:-4px;margin-right:4px">' + icon('arrow-up', 16) + '</span>.. (up)</button></td></tr>');
        }
        if (!folders.length && !files.length) {
            tb.innerHTML += '<tr><td colspan="6"><div class="empty">' + icon('folder') + '<span>No objects here.</span></div></td></tr>';
        }
        folders.forEach(f => {
            const full = state.prefix + f;
            visibleKeys.push(full);
            tb.insertAdjacentHTML('beforeend',
                '<tr class="dir' + (state.sel.has(full) ? ' sel' : '') + '"><td><input type="checkbox" class="rowcheck" data-key="' + esc(full) + '"' + (state.sel.has(full) ? ' checked' : '') + '></td>' +
                '<td><button class="link" data-folder="' + esc(full) + '"><span class="cellname">' + fileIcon(full) + '<span class="nm">' + esc(f.replace(/\/$/, '')) + '/</span></span></button></td><td></td><td></td>' +
                '<td class="muted">folder</td>' +
                '<td class="actions"><button class="icon-btn sm" data-menu="' + esc(full) + '" title="More actions" aria-label="More actions">' + icon('more', 18) + '</button></td></tr>');
        });
        files.forEach(o => {
            const name = o.key.slice(state.prefix.length);
            visibleKeys.push(o.key);
            tb.insertAdjacentHTML('beforeend',
                '<tr data-key="' + esc(o.key) + '"' + (state.sel.has(o.key) ? ' class="sel"' : '') + '>' +
                '<td><input type="checkbox" class="rowcheck" data-key="' + esc(o.key) + '"' + (state.sel.has(o.key) ? ' checked' : '') + '></td>' +
                '<td><span class="cellname">' + fileIcon(o.key) + '<span class="nm" title="' + esc(name) + '">' + esc(name) + '</span></span></td>' +
                '<td class="muted">' + esc(fmtBytes(o.size)) + '</td>' +
                '<td class="muted" title="' + esc(fmtTime(o.last_modified)) + '">' + esc(fmtRel(o.last_modified)) + '</td>' +
                '<td class="muted">' + esc(fileExt(o.key)) + '</td>' +
                '<td class="actions">' +
                '<button class="icon-btn sm" data-info="' + esc(o.key) + '" title="Details" aria-label="Details">' + icon('info', 18) + '</button>' +
                '<button class="icon-btn sm" data-dl="' + esc(o.key) + '" title="Download" aria-label="Download">' + icon('download', 18) + '</button>' +
                '<button class="icon-btn sm" data-menu="' + esc(o.key) + '" title="More actions" aria-label="More actions">' + icon('more', 18) + '</button>' +
                '</td></tr>');
        });
    } else {
        const grid = $('#fileGrid');
        grid.innerHTML = '';
        if (state.prefix) {
            grid.insertAdjacentHTML('beforeend',
                '<div class="gcard" data-up="1"><div class="gthumb">' + icon('arrow-up') + '</div><div class="gname">.. (up)</div><div class="gsub">parent folder</div></div>');
        }
        if (!folders.length && !files.length) {
            grid.innerHTML = '<div class="empty" style="grid-column:1/-1">' + icon('folder') + '<span>No objects here. Drag &amp; drop files to upload.</span></div>';
        }
        folders.forEach(f => {
            const full = state.prefix + f;
            visibleKeys.push(full);
            grid.insertAdjacentHTML('beforeend',
                '<div class="gcard' + (state.sel.has(full) ? ' sel' : '') + '" data-key="' + esc(full) + '" data-folder="' + esc(full) + '">' +
                '<div class="gthumb folder">' + icon('folder') + '</div>' +
                '<div class="gname" title="' + esc(full) + '">' + esc(f.replace(/\/$/, '')) + '</div><div class="gsub">folder</div></div>');
        });
        files.forEach(o => {
            const name = o.key.slice(state.prefix.length);
            visibleKeys.push(o.key);
            const vt = fileViewType(o);
            const thumb = vt === 'img'
                ? '<div class="gthumb"><img loading="lazy" src="api.php?action=download_object&bucket_id=' + state.bucketId + '&key=' + encodeURIComponent(o.key) + '&inline=1" alt=""></div>'
                : '<div class="gthumb">' + fileIcon(o.key).replace('<span class="ficon', '<span class="ficon" style="width:36px;height:36px" ') + '</div>';
            grid.insertAdjacentHTML('beforeend',
                '<div class="gcard' + (state.sel.has(o.key) ? ' sel' : '') + '" data-key="' + esc(o.key) + '" data-info="' + esc(o.key) + '">' +
                thumb +
                '<div class="gname" title="' + esc(name) + '">' + esc(name) + '</div>' +
                '<div class="gsub" title="' + esc(fmtTime(o.last_modified)) + '">' + esc(fmtBytes(o.size)) + ' · ' + esc(fmtRel(o.last_modified)) + '</div></div>');
        });
    }
    updateViewToggle();
    $('#selAll').checked = visibleKeys.length > 0 && visibleKeys.every(k => state.sel.has(k));
    $('#deleteFolderBtn').classList.toggle('hidden', !state.prefix);
    updateBulkBar();
    renderPager('objPager', state.objPage, state.objPages, state.objPerPage,
        p => { state.objPage = p; loadFiles(); },
        n => { state.objPerPage = n; state.objPage = 1; loadFiles(); });
}

/* ---------- selection & bulk actions ---------- */
function updateBulkBar() {
    const n = state.sel.size;
    $('#bulkLabel').textContent = n + ' item(s) selected';
    $('#bulkBar').classList.toggle('visible', n > 0);
    $('#bulkCopyBtn').disabled = n === 0;
    $('#bulkMoveBtn').disabled = n === 0;
    $('#bulkDeleteBtn').disabled = n === 0;
}
function clearSel() {
    state.sel.clear();
    $('#selAll').checked = false;
    updateBulkBar();
}

$('#filesTbody').addEventListener('click', e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    if (btn.dataset.up) {
        state.prefix = btn.dataset.up === '1' ? state.prefix.replace(/[^/]+\/?$/, '') : '';
        state.objPage = 1; state.objQ = ''; $('#objSearch').value = '';
        clearSel(); saveFilesView(); loadFiles(); return;
    }
    if (btn.dataset.folder) {
        state.prefix = btn.dataset.folder;
        state.objPage = 1; state.objQ = ''; $('#objSearch').value = '';
        clearSel(); saveFilesView(); loadFiles(); return;
    }
    if (btn.dataset.dl) {
        window.open('api.php?action=download_object&bucket_id=' + state.bucketId + '&key=' + encodeURIComponent(btn.dataset.dl));
        return;
    }
    if (btn.dataset.view) {
        if (btn.dataset.viewtype === 'text') openTextEditor(btn.dataset.view);
        else openMediaView(btn.dataset.view, btn.dataset.viewtype);
        return;
    }
    if (btn.dataset.info) { openInfo(btn.dataset.info); return; }
    if (btn.dataset.menu) { openRowMenu(btn, btn.dataset.menu); return; }
    if (btn.dataset.rename) { openRename(btn.dataset.rename); return; }
    if (btn.dataset.del) { deleteObject(btn.dataset.del); return; }
});

// Grid view: click opens folder / details; right-click (or long-press) opens the menu.
$('#fileGrid').addEventListener('click', e => {
    const card = e.target.closest('.gcard');
    if (!card) return;
    if (card.dataset.up) {
        state.prefix = state.prefix.replace(/[^/]+\/?$/, '');
        state.objPage = 1; state.objQ = ''; $('#objSearch').value = '';
        clearSel(); saveFilesView(); loadFiles(); return;
    }
    if (card.dataset.folder) {
        state.prefix = card.dataset.folder;
        state.objPage = 1; state.objQ = ''; $('#objSearch').value = '';
        clearSel(); saveFilesView(); loadFiles(); return;
    }
    if (card.dataset.info) openInfo(card.dataset.info);
});
$('#fileGrid').addEventListener('contextmenu', e => {
    const card = e.target.closest('.gcard[data-key]');
    if (!card) return;
    e.preventDefault();
    openRowMenu(card, card.dataset.key, e);
});

// ---------- double-click preview ----------
function previewObjectByKey(key) {
    const o = state.objects.find(x => x.key === key);
    if (!o) return;
    const vt = fileViewType(o);
    if (vt === 'text') openTextEditor(key);
    else if (vt) openMediaView(key, vt);
}

$('#filesTbody').addEventListener('dblclick', e => {
    const tr = e.target.closest('tr[data-key]');
    if (!tr || tr.classList.contains('dir')) return;
    previewObjectByKey(tr.dataset.key);
});

$('#fileGrid').addEventListener('dblclick', e => {
    const card = e.target.closest('.gcard[data-key]');
    if (!card || card.dataset.folder || card.dataset.up) return;
    previewObjectByKey(card.dataset.key);
});

/* ---------- context menu for file rows ---------- */
function closeRowMenu() {
    const m = document.getElementById('rowCtxMenu');
    if (m) m.remove();
}
document.addEventListener('click', e => {
    if (!e.target.closest('#rowCtxMenu') && !e.target.closest('[data-menu]')) closeRowMenu();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRowMenu(); });

function openRowMenu(anchor, key, ev) {
    closeRowMenu();
    const isFolder = key.endsWith('/');
    const vt = !isFolder ? fileViewType(state.objects.find(o => o.key === key) || { key, content_type: '' }) : null;
    const items = [];
    if (vt) items.push(['view', 'eye', vt === 'text' ? 'View / edit' : 'Preview']);
    if (!isFolder) {
        items.push(['dl', 'download', 'Download']);
        items.push(['share', 'share', 'Share link...']);
        items.push(['info', 'info', 'Details']);
    }
    items.push(['rename', 'rename', 'Rename']);
    items.push(['del', 'trash', 'Delete']);
    const m = document.createElement('div');
    m.className = 'ctxmenu';
    m.id = 'rowCtxMenu';
    m.setAttribute('role', 'menu');
    items.forEach(([act, ic, label]) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'ci' + (act === 'del' ? ' danger' : '');
        b.setAttribute('role', 'menuitem');
        b.innerHTML = icon(ic, 18) + '<span>' + esc(label) + '</span>';
        b.onclick = evv => {
            evv.stopPropagation();
            closeRowMenu();
            if (act === 'view') { vt === 'text' ? openTextEditor(key) : openMediaView(key, vt); }
            if (act === 'dl') window.open('api.php?action=download_object&bucket_id=' + state.bucketId + '&key=' + encodeURIComponent(key));
            if (act === 'share') openShare(key);
            if (act === 'info') openInfo(key);
            if (act === 'rename') openRename(key);
            if (act === 'del') deleteObject(key);
        };
        m.appendChild(b);
    });
    document.body.appendChild(m);
    const r = anchor.getBoundingClientRect();
    const mw = 200, mh = m.offsetHeight || items.length * 42 + 16;
    let x = ev ? ev.clientX : r.right - mw;
    let y = ev ? ev.clientY : r.bottom + 4;
    if (x + mw > innerWidth - 8) x = innerWidth - mw - 8;
    if (y + mh > innerHeight - 8) y = Math.max(8, y - mh);
    m.style.left = x + 'px';
    m.style.top = y + 'px';
    m.style.minWidth = mw + 'px';
}

/* ---------- object details ---------- */
async function openInfo(key) {
    openModal('<h3>Details</h3><p style="overflow-wrap:anywhere">' + esc(key) + '</p><div class="empty">' + icon('file-text') + '<span>Loading...</span></div>');
    let d;
    try {
        d = await api('objects', { json: { _sub: 'info', bucket_id: state.bucketId, key } });
    } catch (err) {
        $('#modalBox .empty').lastElementChild.textContent = err.message;
        return;
    }
    const metaRows = Object.keys(d.meta || {}).map(k =>
        '<tr><td class="muted">' + esc(k) + '</td><td><code>' + esc(String(d.meta[k])) + '</code></td></tr>').join('');
    openModal(
        '<h3 style="display:flex;align-items:center;gap:10px">' + fileIcon(d.key).replace('<span class="ficon', '<span class="ficon" style="width:28px;height:28px" ') + '<span style="min-width:0;overflow-wrap:anywhere">' + esc(d.key.slice(state.prefix.length)) + '</span></h3>' +
        '<table class="grid"><tbody>' +
        '<tr><td class="muted">Bucket</td><td>' + esc(d.username) + ' / ' + esc(d.bucket) + '</td></tr>' +
        '<tr><td class="muted">Size</td><td>' + esc(fmtBytes(d.size)) + '</td></tr>' +
        '<tr><td class="muted">Type</td><td><code>' + esc(d.content_type) + '</code></td></tr>' +
        '<tr><td class="muted">ETag</td><td><code>' + esc(d.etag) + '</code></td></tr>' +
        '<tr><td class="muted">Modified</td><td>' + esc(fmtTime(d.last_modified)) + ' <span class="muted">(' + esc(fmtRel(d.last_modified)) + ')</span></td></tr>' +
        (metaRows || '') +
        '</tbody></table>' +
        '<div class="modal-actions">' +
        (d.is_folder ? '' :
        '<button id="detShareBtn" class="btn btn-tonal"><span class="bi">' + icon('share', 16) + '</span>Share link</button>' +
        '<button id="detDlBtn" class="btn btn-tonal"><span class="bi">' + icon('download', 16) + '</span>Download</button>') +
        '<button class="btn btn-text" data-close>Close</button>' +
        '</div>');
    if ($('#detDlBtn')) $('#detDlBtn').onclick = () => window.open('api.php?action=download_object&bucket_id=' + state.bucketId + '&key=' + encodeURIComponent(key));
    if ($('#detShareBtn')) $('#detShareBtn').onclick = () => openShare(key);
}

/* ---------- presigned share link ---------- */
function openShare(key) {
    openModal(
        '<h3>Share link</h3>' +
        '<p style="overflow-wrap:anywhere">' + esc(key) + '</p>' +
        '<p>Anyone with this link can download the file until it expires. No login required.</p>' +
        '<div class="tf float"><select id="shareExpires" class="has-value">' +
        '<option value="300">5 minutes</option>' +
        '<option value="3600" selected>1 hour</option>' +
        '<option value="86400">1 day</option>' +
        '<option value="604800">7 days</option>' +
        '</select><label>Valid for</label><span class="tf-caret">' + icon('chevron-down', 18) + '</span></div>' +
        '<div id="shareResult"></div>' +
        '<div class="modal-actions">' +
        '<button id="shareGenBtn" class="btn btn-filled"><span class="bi">' + icon('share', 16) + '</span>Generate link</button>' +
        '<button class="btn btn-text" data-close>Close</button>' +
        '</div>');
    $('#shareGenBtn').onclick = async () => {
        const btn = $('#shareGenBtn');
        btn.disabled = true;
        try {
            const d = await api('objects', { json: { _sub: 'presign', bucket_id: state.bucketId, key, expires: Number($('#shareExpires').value) } });
            $('#shareResult').innerHTML =
                '<div class="tf"><input readonly value="' + esc(d.url) + '" placeholder=" " id="shareUrl"><label>Download URL</label></div>' +
                '<div style="display:flex;gap:8px;margin-top:6px">' +
                '<button id="shareCopyBtn" class="btn btn-tonal btn-sm"><span class="bi">' + icon('copy', 15) + '</span>Copy</button>' +
                '<button id="shareOpenBtn" class="btn btn-outlined btn-sm">Open</button>' +
                '</div>';
            $('#shareCopyBtn').onclick = () => copyText(d.url);
            $('#shareOpenBtn').onclick = () => window.open(d.url, '_blank', 'noopener');
            btn.textContent = 'Regenerate link';
            btn.disabled = false;
        } catch (err) {
            toast(err.message, 'err');
            btn.disabled = false;
        }
    };
}

$('#selAll').addEventListener('change', e => {
    const check = e.target.checked;
    const { folders, files } = groupObjects(state.objects, state.prefix);
    state.sel.clear();
    if (check) {
        folders.forEach(f => state.sel.add(state.prefix + f));
        files.forEach(o => state.sel.add(o.key));
    }
    updateBulkBar();
    renderFiles();
});

$('#filesTbody').addEventListener('change', e => {
    const ck = e.target.closest('.rowcheck');
    if (!ck) return;
    const key = ck.dataset.key;
    if (ck.checked) state.sel.add(key); else state.sel.delete(key);
    ck.closest('tr').classList.toggle('sel', ck.checked);
    const checks = document.querySelectorAll('#filesTbody .rowcheck');
    $('#selAll').checked = checks.length > 0 && [...checks].every(c => c.checked);
    updateBulkBar();
});

$('#pathbar').addEventListener('click', e => {
    const c = e.target.closest('.crumb');
    if (!c) return;
    state.prefix = c.dataset.prefix;
    state.objPage = 1; state.objQ = ''; $('#objSearch').value = '';
    clearSel(); saveFilesView(); loadFiles();
});

$('#objSearch').addEventListener('input', e => {
    clearTimeout(state._objSearchT);
    state._objSearchT = setTimeout(() => { state.objQ = e.target.value.trim(); state.objPage = 1; loadFiles(); }, 350);
});
$('#refreshFilesBtn').addEventListener('click', loadFiles);

/* ---------- list / grid view toggle ---------- */
function updateViewToggle() {
    const b = $('#viewToggleBtn');
    if (b) b.innerHTML = icon(state.objView === 'grid' ? 'list' : 'grid', 20);
}
try {
    const v = localStorage.getItem('minis3_objview');
    if (v === 'grid' || v === 'list') state.objView = v;
} catch (e) {}
$('#viewToggleBtn').addEventListener('click', () => {
    state.objView = state.objView === 'grid' ? 'list' : 'grid';
    try { localStorage.setItem('minis3_objview', state.objView); } catch (e) {}
    renderFiles();
});

/* ---------- folder ZIP download ---------- */
$('#zipBtn').addEventListener('click', () => {
    window.open('api.php?action=objects&_sub=zip&bucket_id=' + state.bucketId + '&prefix=' + encodeURIComponent(state.prefix));
});

/* ---------- drag & drop upload ---------- */
(() => {
    const fv = $('#filesView');
    let depth = 0;
    fv.addEventListener('dragenter', e => {
        if (!e.dataTransfer || ![...(e.dataTransfer.types || [])].includes('Files')) return;
        e.preventDefault();
        depth++;
        fv.classList.add('droptarget');
    });
    fv.addEventListener('dragover', e => {
        if (!e.dataTransfer || ![...(e.dataTransfer.types || [])].includes('Files')) return;
        e.preventDefault();
    });
    fv.addEventListener('dragleave', () => {
        depth = Math.max(0, depth - 1);
        if (depth === 0) fv.classList.remove('droptarget');
    });
    fv.addEventListener('drop', e => {
        if (!e.dataTransfer) return;
        e.preventDefault();
        depth = 0;
        fv.classList.remove('droptarget');
        const files = [...e.dataTransfer.files];
        if (files.length) openUploadModal(files);
    });
})();

/* ---------- paste & keyboard shortcuts ---------- */
document.addEventListener('paste', e => {
    if ($('#filesView').classList.contains('hidden') || !$('#modalOverlay').classList.contains('hidden')) return;
    const files = [...((e.clipboardData && e.clipboardData.files) || [])];
    if (files.length) {
        e.preventDefault();
        openUploadModal(files);
    }
});
document.addEventListener('keydown', e => {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    const t = e.target;
    const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
    if (e.key === '/' && !typing) {
        const active = document.querySelector('.nav-dest.active');
        if (!$('#filesView').classList.contains('hidden')) { e.preventDefault(); $('#objSearch').focus(); }
        else if (active && active.dataset.tab === 'logs') { e.preventDefault(); $('#logSearch').focus(); }
    }
    if ((e.key === 'u' || e.key === 'U') && !typing
        && !$('#filesView').classList.contains('hidden') && $('#modalOverlay').classList.contains('hidden')) {
        e.preventDefault();
        $('#uploadInput').click();
    }
});

$('#bulkClearBtn').addEventListener('click', () => { clearSel(); renderFiles(); });

$('#bulkDeleteBtn').addEventListener('click', () => {
    const n = state.sel.size;
    confirmDialog('Delete ' + n + ' selected item(s)? Folders delete everything inside them. This cannot be undone.', 'Delete', async () => {
        try {
            const d = await api('objects', { json: { _sub: 'bulk_delete', bucket_id: state.bucketId, keys: [...state.sel] } });
            toast('Deleted ' + d.deleted + ' object(s)', 'ok');
            clearSel();
            await loadFiles();
        } catch (err) { toast(err.message, 'err'); }
    });
});

async function loadFolders() {
    try {
        const d = await api('folders', { method: 'GET', params: { bucket_id: state.bucketId } });
        state.folders = d.folders || [];
    } catch (err) {
        state.folders = [];
        toast('Could not load folder list', 'err');
    }
}

function openTransfer(mode) {
    const label = mode === 'move' ? 'Move' : 'Copy';
    const cur = state.prefix;
    openModal(
        '<h3>' + label + ' ' + state.sel.size + ' selected item(s)</h3>' +
        '<p>Choose the destination folder inside the bucket.</p>' +
        '<form id="transferForm">' +
        '<div class="tf float"><label>Destination folder</label></div>' +
        '<div class="folder-picker">' +
        '<div class="fp-search"><input id="fpSearch" placeholder="Filter folders..." autocomplete="off"></div>' +
        '<div class="fp-list" id="fpList"></div>' +
        '</div>' +
        '<input type="hidden" id="fpValue" value="' + esc(cur) + '">' +
        '<div class="tf"><input name="custom" placeholder=" " autocomplete="off"><label>Or type a custom path (e.g. Backup/2026/)</label></div>' +
        '<label class="check"><input type="checkbox" name="overwrite"> Overwrite existing objects with the same name</label>' +
        '<div class="modal-actions"><button type="submit" class="btn ' + (mode === 'copy' ? 'btn-filled' : 'btn-danger-filled') + '">' + label + '</button><button type="button" class="btn btn-text" data-close>Cancel</button></div>' +
        '</form>');
    const fpList = $('#fpList');
    const renderList = () => {
        const q = ($('#fpSearch').value || '').toLowerCase();
        const sel = $('#fpValue').value;
        fpList.innerHTML = '';
        if (!q) {
            const root = document.createElement('div');
            root.className = 'fp-item' + ('' === sel ? ' sel' : '');
            root.innerHTML = icon('hard-drive', 17) + '<span>Bucket root /</span>';
            root.onclick = () => {
                $('#fpValue').value = '';
                document.querySelectorAll('.fp-item').forEach(x => x.classList.remove('sel'));
                root.classList.add('sel');
            };
            fpList.appendChild(root);
        }
        state.folders.forEach(f => {
            if (q && !f.toLowerCase().includes(q)) return;
            const depth = Math.max(0, (f.match(/\//g) || []).length - 1);
            const name = f === '/' ? '/' : f.replace(/\/$/, '').split('/').pop() + '/';
            const div = document.createElement('div');
            div.className = 'fp-item' + (f === sel ? ' sel' : '');
            div.style.paddingLeft = (14 + depth * 18) + 'px';
            div.innerHTML = icon('folder', 17) + '<span style="overflow:hidden;text-overflow:ellipsis">' + esc(name) + '</span>';
            div.title = f;
            div.onclick = () => {
                $('#fpValue').value = f;
                document.querySelectorAll('.fp-item').forEach(x => x.classList.remove('sel'));
                div.classList.add('sel');
            };
            fpList.appendChild(div);
        });
    };
    $('#fpSearch').addEventListener('input', renderList);
    renderList();
    $('#transferForm').onsubmit = async ev => {
        ev.preventDefault();
        let dest = $('#transferForm').custom.value.trim() || $('#fpValue').value.trim();
        if (dest === '/' || dest === '') dest = '';
        else if (!dest.endsWith('/')) dest += '/';
        try {
            const d = await api('objects', {
                json: { _sub: mode, bucket_id: state.bucketId, keys: [...state.sel], src_prefix: state.prefix, dest_prefix: dest, overwrite: $('#transferForm').overwrite.checked }
            });
            closeModal();
            toast(label + 'd ' + d.copied + ' object(s), ' + d.deleted + ' removed', 'ok');
            clearSel();
            await loadFiles();
        } catch (err) {
            let msg = err.message;
            if (err.data && err.data.conflicts && err.data.conflicts.length) {
                msg += ' Conflict: ' + err.data.conflicts.slice(0, 5).join(', ') + (err.data.conflicts.length > 5 ? ', ...' : '');
            }
            toast(msg, 'err');
        }
    };
}

$('#bulkCopyBtn').addEventListener('click', () => { loadFolders().then(() => openTransfer('copy')); });
$('#bulkMoveBtn').addEventListener('click', () => { loadFolders().then(() => openTransfer('move')); });

function openRename(key) {
    const isFolder = key.endsWith('/');
    const name = key.slice(state.prefix.length).replace(/\/$/, '');
    openModal(
        '<h3>Rename</h3>' +
        '<p style="overflow-wrap:anywhere">' + esc(key) + '</p>' +
        '<form id="renameForm">' +
        '<div class="tf"><input name="name" value="' + esc(name) + '" required pattern="[^\\/]+" autocomplete="off" placeholder=" "><label>New name</label></div>' +
        '<div class="modal-actions"><button type="submit" class="btn btn-filled">Rename</button><button type="button" class="btn btn-text" data-close>Cancel</button></div>' +
        '</form>');
    $('#renameForm').onsubmit = async ev => {
        ev.preventDefault();
        const nn = $('#renameForm').name.value.trim();
        const newKey = isFolder ? state.prefix + nn + '/' : state.prefix + nn;
        if (newKey === key) { closeModal(); return; }
        try {
            await api('objects', { json: { _sub: 'rename', bucket_id: state.bucketId, key, new_key: newKey } });
            closeModal();
            toast('Renamed', 'ok');
            await loadFiles();
        } catch (err) { toast(err.message, 'err'); }
    };
}

/* ---------- file view / edit ---------- */
// Returns 'text', 'img', 'video' or 'audio' when the object can be previewed
// in the browser, otherwise null.
function fileViewType(o) {
    const ct = String(o.content_type || '').toLowerCase();
    if (ct.indexOf('image/') === 0) return 'img';
    if (ct.indexOf('video/') === 0) return 'video';
    if (ct.indexOf('audio/') === 0) return 'audio';
    if (ct === 'application/pdf') return 'pdf';
    if (ct.indexOf('text/') === 0 || ct.indexOf('+xml') > 0 || ct === 'application/json' || ct === 'application/xml'
        || ct === 'application/javascript' || ct === 'application/x-httpd-php' || ct === 'application/x-sh'
        || ct === 'image/svg+xml' || ct.indexOf('json') > 0 || ct.indexOf('yaml') > 0) return 'text';
    const ext = (String(o.key).split('.').pop() || '').toLowerCase();
    const imgs = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif'];
    const vids = ['mp4', 'webm', 'ogv', 'm4v', 'mov', 'mkv', 'avi'];
    const auds = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus'];
    const txts = ['txt', 'text', 'md', 'markdown', 'log', 'conf', 'cfg', 'ini', 'json', 'xml', 'yml', 'yaml',
        'toml', 'csv', 'tsv', 'html', 'htm', 'css', 'js', 'mjs', 'php', 'py', 'rb', 'pl', 'sh', 'bash',
        'zsh', 'bat', 'cmd', 'ps1', 'sql', 'env', 'htaccess', 'properties', 'srt', 'vtt', 'nfo'];
    if (ext === 'pdf') return 'pdf';
    if (imgs.indexOf(ext) >= 0) return 'img';
    if (vids.indexOf(ext) >= 0) return 'video';
    if (auds.indexOf(ext) >= 0) return 'audio';
    if (txts.indexOf(ext) >= 0) return 'text';
    return null;
}

async function openTextEditor(key) {
    openModal('<h3>View / edit</h3><p style="overflow-wrap:anywhere">' + esc(key) + '</p><div id="textLoad" class="empty">' + icon('file-text') + '<span>Loading...</span></div>');
    let d;
    try {
        d = await api('object_content', { method: 'GET', params: { bucket_id: state.bucketId, key } });
    } catch (err) {
        $('#textLoad').lastElementChild.textContent = err.message;
        return;
    }
    const limitKB = 512;
    $('#modalBox').classList.add('wide');
    $('#modalBox').innerHTML =
        '<h3>View / edit</h3>' +
        '<div class="editor-hint"><span class="muted">' + esc(key) + '</span><span class="muted">' + esc(fmtBytes(d.size)) + ' &middot; max ' + limitKB + ' KB</span></div>' +
        '<textarea id="textContent" spellcheck="false" wrap="off" aria-label="File content"></textarea>' +
        '<div class="modal-actions">' +
        '<button id="textSaveBtn" class="btn btn-filled"><span class="bi">' + icon('check', 16) + '</span>Save</button>' +
        '<button id="textReloadBtn" class="btn btn-tonal"><span class="bi">' + icon('refresh', 16) + '</span>Reload</button>' +
        '<button class="btn btn-text" data-close>Close</button>' +
        '</div>';
    const ta = $('#textContent');
    ta.value = d.content;
    const save = async () => {
        const btn = $('#textSaveBtn');
        btn.disabled = true;
        try {
            await api('objects', { json: { _sub: 'update_content', bucket_id: state.bucketId, key, content: ta.value } });
            closeModal();
            toast('Saved', 'ok');
            await loadFiles();
        } catch (err) {
            toast(err.message, 'err');
            btn.disabled = false;
        }
    };
    $('#textSaveBtn').onclick = save;
    $('#textReloadBtn').onclick = () => openTextEditor(key);
    ta.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            save();
        }
    });
}

function openMediaView(key, type) {
    const url = 'api.php?action=download_object&bucket_id=' + state.bucketId + '&key=' + encodeURIComponent(key) + '&inline=1';
    if (type === 'pdf' && matchMedia('(pointer: coarse)').matches) {
        // Mobile browsers cannot render PDFs inside an iframe and instead
        // download the file (named after the URL, e.g. "api.php"). Open it
        // in a new tab where the native mobile PDF viewer works.
        window.open(url, '_blank', 'noopener');
        return;
    }
    let inner;
    if (type === 'img') inner = '<div id="mediaWrap" class="media-wrap"><span class="muted">Loading preview...</span></div>';
    else if (type === 'video') inner = '<video id="mediaVideo" controls preload="metadata" class="media-video"></video>';
    else if (type === 'pdf') inner = '<iframe id="mediaPdf" class="media-pdf" src="' + esc(url) + '#toolbar=0" title="PDF preview"></iframe>';
    else inner = '<audio id="mediaAudio" controls preload="metadata" class="media-audio"></audio>';
    openModal(
        '<h3 style="overflow-wrap:anywhere">' + esc(key) + '</h3>' +
        inner +
        '<div class="modal-actions">' +
        '<button id="dlBtn" class="btn btn-filled"><span class="bi">' + icon('download', 16) + '</span>Download</button>' +
        '<button class="btn btn-text" data-close>Close</button>' +
        '</div>');
    $('#modalBox').classList.add('wide');
    if (type === 'img') {
        const img = new Image();
        img.className = 'media-img';
        img.alt = key;
        img.onload = () => {
            const w = $('#mediaWrap');
            w.innerHTML = '';
            w.appendChild(img);
        };
        img.onerror = () => { $('#mediaWrap').textContent = 'Could not load preview.'; };
        img.src = url;
    } else if (type === 'video') {
        $('#mediaVideo').src = url;
    } else if (type === 'audio') {
        $('#mediaAudio').src = url;
    }
    $('#dlBtn').onclick = () => {
        window.open('api.php?action=download_object&bucket_id=' + state.bucketId + '&key=' + encodeURIComponent(key));
    };
}

$('#newFolderBtn').addEventListener('click', () => {
    openModal(
        '<h3>New folder</h3>' +
        '<p>Created in: <span style="overflow-wrap:anywhere">' + esc(state.prefix || '(bucket root)') + '</span></p>' +
        '<form id="folderForm">' +
        '<div class="tf"><input name="name" required pattern="[^\\/]+" placeholder=" " autocomplete="off"><label>Folder name</label></div>' +
        '<div class="modal-actions"><button type="submit" class="btn btn-filled"><span class="bi">' + icon('folder-plus', 16) + '</span>Create</button><button type="button" class="btn btn-text" data-close>Cancel</button></div>' +
        '</form>');
    $('#folderForm').onsubmit = async ev => {
        ev.preventDefault();
        const fd = new FormData($('#folderForm'));
        fd.append('_sub', 'create_folder');
        fd.append('bucket_id', state.bucketId);
        fd.append('prefix', state.prefix);
        try {
            await api('objects', { form: fd });
            closeModal();
            toast('Folder created', 'ok');
            await loadFiles();
        } catch (err) { toast(err.message, 'err'); }
    };
});

$('#newFileBtn').addEventListener('click', () => {
    $('#modalBox').classList.add('wide');
    openModal(
        '<h3>New file</h3>' +
        '<p>Created in: <span style="overflow-wrap:anywhere">' + esc(state.prefix || '(bucket root)') + '</span></p>' +
        '<form id="fileForm">' +
        '<div class="tf"><input name="name" required pattern="[^\\/]+" placeholder=" " autocomplete="off"><label>File name (e.g. notes.txt)</label></div>' +
        '<div class="tf"><textarea name="content" id="fileContent" spellcheck="false" wrap="off" placeholder=" " autocomplete="off" aria-label="File content"></textarea><label>Content</label></div>' +
        '<div class="modal-actions"><button type="submit" class="btn btn-filled"><span class="bi">' + icon('file-plus', 16) + '</span>Create</button><button type="button" class="btn btn-text" data-close>Cancel</button></div>' +
        '</form>');
    $('#fileForm').onsubmit = async ev => {
        ev.preventDefault();
        const fd = new FormData($('#fileForm'));
        try {
            await api('objects', { json: { _sub: 'create_file', bucket_id: state.bucketId, prefix: state.prefix, name: fd.get('name'), content: fd.get('content') } });
            closeModal();
            toast('File created', 'ok');
            await loadFiles();
        } catch (err) { toast(err.message, 'err'); }
    };
});

$('#uploadFileBtn').addEventListener('click', () => $('#uploadInput').click());
$('#uploadInput').addEventListener('change', () => {
    const files = [...$('#uploadInput').files];
    $('#uploadInput').value = '';
    if (files.length) openUploadModal(files);
});

function openUploadModal(files) {
    const totalBytes = () => files.reduce((s, f) => s + f.size, 0);
    const renderUploadList = () => {
        const list = $('#uploadList');
        list.innerHTML = '';
        files.forEach((f, i) => {
            const row = document.createElement('div');
            row.className = 'upload-row';
            row.innerHTML = '<span style="color:var(--on-surface-var)">' + icon('file', 18) + '</span>' +
                '<span class="upload-name">' + esc(f.name) + '</span>' +
                '<span class="muted" style="white-space:nowrap">' + esc(fmtBytes(f.size)) + '</span>' +
                '<span class="upload-state" id="us' + i + '"></span>';
            list.appendChild(row);
        });
        $('#uploadStartBtn').textContent = 'Upload ' + fmtBytes(totalBytes());
    };
    openModal(
        '<h3>Upload file(s) into <span style="overflow-wrap:anywhere">' + esc(state.prefix || 'bucket root') + '</span></h3>' +
        '<div style="max-height:220px;overflow:auto;background:var(--surface-1);border:1px solid var(--outline-var);border-radius:12px;padding:6px 12px"><div id="uploadList"></div></div>' +
        '<div id="uploadConflict" class="hidden" style="margin:10px 0">' +
        '<div class="conflict-note"><span>' + icon('alert', 20) + '<span id="conflictList"></span></span></div>' +
        '<label class="check"><input type="checkbox" id="overwriteChk"> Replace existing file(s) with the same name</label>' +
        '</div>' +
        '<div class="upload-progress hidden" id="uploadProg">' +
        '<div class="progress"><div id="uploadBar"></div></div>' +
        '<span class="muted" id="uploadStatus" style="min-width:84px;text-align:right">0%</span>' +
        '</div>' +
        '<div class="modal-actions">' +
        '<button type="button" id="uploadMoreBtn" class="btn btn-tonal"><span class="bi">' + icon('plus', 16) + '</span>Add more</button>' +
        '<input type="file" id="uploadMoreInput" class="hidden" multiple>' +
        '<button id="uploadStartBtn" class="btn btn-filled"></button>' +
        '<button type="button" class="btn btn-text" data-close>Cancel</button>' +
        '</div>');
    renderUploadList();
    let conflicts = [];
    const updateStartState = () => {
        $('#uploadStartBtn').disabled = conflicts.length > 0 && !$('#overwriteChk').checked;
    };
    const refreshConflicts = async () => {
        const keys = files.map(f => state.prefix + f.name);
        $('#uploadStartBtn').disabled = true;
        try {
            const d = await api('object_conflicts', { method: 'GET', params: { bucket_id: state.bucketId, keys: JSON.stringify(keys) } });
            conflicts = d.conflicts || [];
        } catch (err) {
            conflicts = [];
        }
        const box = $('#uploadConflict');
        if (conflicts.length) {
            const names = conflicts.slice(0, 6).map(c => c.key.split('/').pop());
            $('#conflictList').textContent = conflicts.length + ' file(s) already exist in this folder: ' + names.join(', ') + (conflicts.length > 6 ? ' +' + (conflicts.length - 6) + ' more' : '');
            box.classList.remove('hidden');
        } else {
            box.classList.add('hidden');
            $('#overwriteChk').checked = false;
        }
        const cset = new Set(conflicts.map(c => c.key));
        files.forEach((f, i) => {
            const stEl = $('#us' + i);
            if (cset.has(state.prefix + f.name)) {
                stEl.textContent = 'exists';
                stEl.className = 'upload-state warn';
            } else if (stEl.textContent === 'exists') {
                stEl.textContent = '';
                stEl.className = 'upload-state';
            }
        });
        updateStartState();
    };
    $('#uploadMoreBtn').onclick = () => $('#uploadMoreInput').click();
    $('#uploadMoreInput').addEventListener('change', () => {
        const more = [...$('#uploadMoreInput').files];
        $('#uploadMoreInput').value = '';
        if (!more.length) return;
        files.push(...more);
        renderUploadList();
        refreshConflicts();
    });
    $('#overwriteChk').addEventListener('change', updateStartState);
    refreshConflicts();
    $('#uploadStartBtn').onclick = async () => {
        if (conflicts.length > 0 && !$('#overwriteChk').checked) return;
        $('#uploadStartBtn').disabled = true;
        $('#uploadMoreBtn').disabled = true;
        $('#uploadProg').classList.remove('hidden');
        const bar = $('#uploadBar');
        const tb = totalBytes();
        let ok = 0, fail = 0, done = 0, firstErr = '', sent = 0, prev = files.map(() => 0);
        let speed = 0, lastT = performance.now();
        for (let i = 0; i < files.length; i++) {
            const f = files[i];
            const st = $('#us' + i);
            st.textContent = 'uploading';
            st.className = 'upload-state';
            await new Promise(resolve => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'api.php?action=objects&_sub=upload&bucket_id=' + state.bucketId + '&prefix=' + encodeURIComponent(state.prefix) + '&name=' + encodeURIComponent(f.name));
                if (state.csrf) xhr.setRequestHeader('X-CSRF-Token', state.csrf);
                xhr.setRequestHeader('Content-Type', f.type || 'application/octet-stream');
                xhr.upload.onprogress = e => {
                    if (e.lengthComputable) {
                        const delta = e.loaded - prev[i];
                        prev[i] = e.loaded;
                        sent += delta;
                        const now = performance.now();
                        const dt = (now - lastT) / 1000;
                        if (dt >= 0.2) {
                            speed = speed > 0 ? speed * 0.7 + (delta / dt) * 0.3 : delta / dt;
                            lastT = now;
                        }
                        const pct = Math.min(100, Math.round(sent / tb * 100));
                        st.textContent = Math.round(e.loaded / e.total * 100) + '%';
                        bar.style.width = pct + '%';
                        $('#uploadStatus').textContent = pct + '% \u00b7 ' + fmtSpeed(speed) + (speed > 0 ? ' \u00b7 ' + fmtEta((tb - sent) / speed) : '');
                    }
                };
                xhr.onload = () => {
                    let data = null;
                    try { data = JSON.parse(xhr.responseText); } catch (e) {}
                    if (xhr.status >= 200 && xhr.status < 300 && data && data.ok === true) {
                        ok++;
                        st.textContent = 'done';
                        st.className = 'upload-state ok';
                    } else {
                        fail++;
                        const msg = (data && data.error) ? data.error : (xhr.status ? 'HTTP ' + xhr.status : 'failed');
                        if (!firstErr) firstErr = msg;
                        st.textContent = msg;
                        st.className = 'upload-state err';
                    }
                    resolve();
                };
                xhr.onerror = () => {
                    fail++;
                    if (!firstErr) firstErr = 'network error';
                    st.textContent = 'network error';
                    st.className = 'upload-state err';
                    resolve();
                };
                xhr.send(f);
            });
            done++;
            const pct = tb > 0 ? Math.round(sent / tb * 100) : 100;
            bar.style.width = pct + '%';
            $('#uploadStatus').textContent = pct + '% \u00b7 ' + fmtSpeed(speed) + ' \u00b7 ' + done + '/' + files.length;
        }
        bar.style.width = '100%';
        $('#uploadStatus').textContent = 'Finished: ' + ok + ' uploaded' + (fail ? ', ' + fail + ' failed' : '');
        const btn = $('#uploadStartBtn');
        btn.disabled = false;
        btn.textContent = 'Done';
        btn.onclick = () => closeModal();
        await loadFiles();
        toast('Uploaded ' + ok + ' file(s)' + (fail ? ', ' + fail + ' failed: ' + (firstErr || 'unknown error') : ''), fail ? 'err' : 'ok');
    };
}

async function deleteObject(key) {
    confirmDialog('Delete "' + key + '"?' + (state.trashEnabled ? ' It will be moved to the trash.' : ''), 'Delete', async () => {
        const fd = new FormData();
        fd.append('_sub', 'delete');
        fd.append('bucket_id', state.bucketId);
        fd.append('key', key);
        try {
            const d = await api('objects', { form: fd });
            await loadFiles();
            toast(d && d.trashed ? 'Moved to trash' : 'Deleted', 'ok');
        } catch (err) { toast(err.message, 'err'); }
    });
}

$('#deleteFolderBtn').addEventListener('click', () => {
    confirmDialog('Delete the current folder "' + state.prefix + '" and everything inside it?', 'Delete', async () => {
        try {
            const d = await api('objects', { json: { _sub: 'bulk_delete', bucket_id: state.bucketId, keys: [state.prefix] } });
            toast('Deleted ' + d.deleted + ' object(s)', 'ok');
            state.prefix = state.prefix.replace(/[^/]+\/?$/, '');
            state.objPage = 1;
            saveFilesView();
            await loadFiles();
        } catch (err) { toast(err.message, 'err'); }
    });
});

/* ---------- logs ---------- */
async function loadLogs() {
    const params = { page: state.logPage, per_page: state.logPerPage };
    if ($('#logUser').value) params.user_id = $('#logUser').value;
    if ($('#logKind').value) params.kind = $('#logKind').value;
    if ($('#logMethod').value) params.method = $('#logMethod').value;
    if ($('#logStatus').value) params.status = $('#logStatus').value;
    if ($('#logSearch').value.trim()) params.q = $('#logSearch').value.trim();
    let d;
    try {
        d = await api('logs', { method: 'GET', params });
    } catch (err) { toast(err.message, 'err'); return; }
    state.logs = d.rows;
    state.logTotal = d.total;
    state.logPage = d.page;
    state.logPages = d.pages;
    state.logPerPage = d.per_page;
    const tb = $('#logsTbody');
    tb.innerHTML = '';
    if (!state.logs.length) {
        tb.innerHTML = '<tr><td colspan="8"><div class="empty">' + icon('file-text') + '<span>No log entries.</span></div></td></tr>';
    } else {
        state.logs.forEach(r => {
            const sClass = r.status >= 500 ? 'st5' : (r.status >= 400 ? 'st4' : 'st2');
            const tr = document.createElement('tr');
            tr.className = 'logrow';
            tr.dataset.id = r.id;
            tr.innerHTML =
                '<td class="muted">' + esc(fmtTime(r.ts)) + '</td>' +
                '<td>' + esc(r.username || (r.kind === 'admin' ? 'admin' : '-')) + '</td>' +
                '<td class="muted">' + esc(r.ip || '') + '</td>' +
                '<td><code>' + esc(r.method || '') + '</code></td>' +
                '<td style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(r.uri || '') + '">' + esc(r.uri || '') + '</td>' +
                '<td><span class="st ' + sClass + '">' + Number(r.status) + '</span></td>' +
                '<td class="muted">' + esc(fmtBytes(r.bytes)) + '</td>' +
                '<td class="muted">' + Number(r.ms).toLocaleString() + '</td>';
            tb.appendChild(tr);
        });
    }
    renderPager('logPager', state.logPage, state.logPages, state.logPerPage,
        p => { state.logPage = p; loadLogs(); },
        n => { state.logPerPage = n; state.logPage = 1; loadLogs(); });
}

$('#logsTbody').addEventListener('click', e => {
    const tr = e.target.closest('tr.logrow');
    if (!tr) return;
    const r = state.logs.find(x => Number(x.id) === Number(tr.dataset.id));
    if (!r) return;
    openModal(
        '<h3>Log entry #' + Number(r.id).toLocaleString() + '</h3>' +
        '<p style="margin:0 0 6px">URI</p><code style="overflow-wrap:anywhere">' + esc(r.uri || '') + '</code>' +
        '<p style="margin:12px 0 6px">Details</p>' +
        '<table class="grid"><tbody>' +
        '<tr><td>Time (local)</td><td>' + esc(fmtTime(r.ts)) + '</td></tr>' +
        '<tr><td>Time (UTC)</td><td><code>' + esc(r.ts) + '</code></td></tr>' +
        '<tr><td>Method</td><td><code>' + esc(r.method || '') + '</code></td></tr>' +
        '<tr><td>Status</td><td><span class="st ' + (r.status >= 500 ? 'st5' : (r.status >= 400 ? 'st4' : 'st2')) + '">' + Number(r.status) + '</span></td></tr>' +
        '<tr><td>Kind</td><td><code>' + esc(r.kind || '') + '</code></td></tr>' +
        '<tr><td>User</td><td>' + esc(r.username || (r.kind === 'admin' ? 'admin' : '-')) + '</td></tr>' +
        '<tr><td>IP</td><td><code>' + esc(r.ip || '') + '</code></td></tr>' +
        '<tr><td>Bytes</td><td>' + esc(fmtBytes(r.bytes)) + '</td></tr>' +
        '<tr><td>Duration</td><td>' + Number(r.ms).toLocaleString() + ' ms</td></tr>' +
        '<tr><td>User agent</td><td><code style="overflow-wrap:anywhere">' + esc(r.user_agent || '') + '</code></td></tr>' +
        '</tbody></table>' +
        '<div class="modal-actions"><button class="btn btn-text" data-close>Close</button></div>');
});

$('#refreshLogsBtn').addEventListener('click', loadLogs);
$('#logUser').addEventListener('change', () => { state.logPage = 1; loadLogs(); });
$('#logKind').addEventListener('change', () => { state.logPage = 1; loadLogs(); });
$('#logMethod').addEventListener('change', () => { state.logPage = 1; loadLogs(); });
$('#logStatus').addEventListener('change', () => { state.logPage = 1; loadLogs(); });
$('#logSearch').addEventListener('input', e => {
    clearTimeout(state._logSearchT);
    state._logSearchT = setTimeout(() => { state.logPage = 1; loadLogs(); }, 350);
});

$('#clearLogsBtn').addEventListener('click', () => {
    confirmDialog('Delete all log entries?', 'Delete', async () => {
        const fd = new FormData();
        fd.append('_sub', 'clear');
        try {
            await api('logs', { form: fd });
            await loadLogs();
            toast('Logs cleared', 'ok');
        } catch (err) { toast(err.message, 'err'); }
    });
});

/* ---------- trash ---------- */
async function loadTrash() {
    let d;
    try {
        d = await api('trash', { method: 'GET', params: { page: state.trashPage, per_page: state.trashPerPage } });
    } catch (err) { toast(err.message, 'err'); return; }
    state.trash = d.rows;
    state.trashTotal = d.total;
    state.trashPage = d.page;
    state.trashPages = d.pages;
    state.trashEnabled = !!d.enabled;
    $('#trashMeta').textContent = d.total.toLocaleString() + ' item(s)';
    $('#trashMeta').classList.remove('hidden');
    const hint = $('#trashHint');
    hint.textContent = d.enabled
        ? 'Deleted files are kept for ' + d.days + ' day(s) and removed automatically afterwards.'
        : 'Trash is disabled - deletions are permanent. Enable it in Settings.';
    hint.classList.toggle('hidden', false);
    const tb = $('#trashTbody');
    tb.innerHTML = '';
    if (!state.trash.length) {
        tb.innerHTML = '<tr><td colspan="7"><div class="empty">' + icon('trash') + '<span>Trash is empty.</span></div></td></tr>';
    } else {
        state.trash.forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td><span class="cellname">' + fileIcon(r.key) + '<span class="nm" title="' + esc(r.key) + '">' + esc(r.key) + '</span></span></td>' +
                '<td class="muted">' + esc(r.bucket_name) + '</td>' +
                '<td class="muted">' + esc(r.username) + '</td>' +
                '<td class="muted">' + esc(fmtBytes(r.size)) + '</td>' +
                '<td class="muted" title="' + esc(fmtTime(r.deleted_at)) + '">' + esc(fmtRel(r.deleted_at)) + '</td>' +
                '<td class="muted" title="' + esc(fmtTime(r.expires_at)) + '">' + esc(fmtRel(r.expires_at).replace(' ago', '')) + '</td>' +
                '<td class="actions">' +
                '<button data-tact="restore" data-id="' + r.id + '" class="btn btn-tonal btn-sm"><span class="bi">' + icon('refresh', 15) + '</span>Restore</button>' +
                '<button data-tact="purge" data-id="' + r.id + '" class="btn btn-danger btn-sm"><span class="bi">' + icon('trash', 15) + '</span></button>' +
                '</td>';
            tb.appendChild(tr);
        });
    }
    renderPager('trashPager', state.trashPage, state.trashPages, state.trashPerPage,
        p => { state.trashPage = p; loadTrash(); },
        n => { state.trashPerPage = n; state.trashPage = 1; loadTrash(); });
}

$('#refreshTrashBtn').addEventListener('click', loadTrash);

$('#trashTbody').addEventListener('click', e => {
    const btn = e.target.closest('button');
    if (!btn) return;
    const id = Number(btn.dataset.id);
    const row = state.trash.find(x => Number(x.id) === id);
    if (!row) return;
    const act = btn.dataset.tact;
    if (act === 'restore') {
        (async () => {
            try {
                await api('trash', { form: new URLSearchParams({ _sub: 'restore', id }) });
                toast('Restored ' + row.key, 'ok');
                await loadTrash();
            } catch (err) { toast(err.message, 'err'); }
        })();
    }
    if (act === 'purge') {
        confirmDialog('Permanently delete "' + row.key + '"? This cannot be undone.', 'Delete', async () => {
            try {
                await api('trash', { form: new URLSearchParams({ _sub: 'purge', id }) });
                toast('Deleted forever', 'ok');
                await loadTrash();
            } catch (err) { toast(err.message, 'err'); }
        });
    }
});

$('#emptyTrashBtn').addEventListener('click', () => {
    confirmDialog('Permanently delete ALL items in the trash?', 'Delete', async () => {
        try {
            await api('trash', { form: new URLSearchParams({ _sub: 'empty' }) });
            toast('Trash emptied', 'ok');
            await loadTrash();
        } catch (err) { toast(err.message, 'err'); }
    });
});

/* ---------- settings: branding (app name + favicon) ---------- */
$('#brandingForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const err = $('#brandingError');
    err.classList.add('hidden');
    try {
        const d = await api('update_settings', { form: new URLSearchParams({ app_name: $('#appNameInput').value }) });
        if (d.app_name) {
            document.title = d.app_name + ' Admin';
            $('#brandName').textContent = d.app_name;
        }
        toast('App name saved', 'ok');
    } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
    }
});

function refreshFavicon() {
    const url = '/favicon.ico?v=' + Date.now();
    $('#faviconPreview').src = url;
    const link = document.querySelector('link[rel="icon"]');
    if (link) link.href = url;
}
$('#faviconUploadBtn').addEventListener('click', () => $('#faviconInput').click());
$('#faviconInput').addEventListener('change', async () => {
    const f = $('#faviconInput').files[0];
    $('#faviconInput').value = '';
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    try {
        await api('upload_favicon', { form: fd });
        toast('Favicon updated', 'ok');
        refreshFavicon();
    } catch (err) { toast(err.message, 'err'); }
});
$('#faviconResetBtn').addEventListener('click', async () => {
    try {
        await api('reset_favicon', { form: new URLSearchParams() });
        toast('Favicon reset to default', 'ok');
        refreshFavicon();
    } catch (err) { toast(err.message, 'err'); }
});

/* ---------- settings: trash retention ---------- */
$('#trashForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const err = $('#trashError');
    err.classList.add('hidden');
    try {
        const d = await api('update_settings', { form: new URLSearchParams({ trash_days: $('#trashDays').value }) });
        state.trashEnabled = Number(d.trash_days) > 0;
        toast('Trash settings saved', 'ok');
    } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
    }
});

/* ---------- settings: two-factor authentication ---------- */
function renderTotpStatus() {
    const st = $('#totpStatus');
    st.innerHTML = state.totp
        ? '<strong style="color:var(--ok)">Enabled</strong> - signing in requires a code from your authenticator app.'
        : '<span class="muted">Not enabled.</span>';
    $('#totpEnableBtn').classList.toggle('hidden', state.totp);
    $('#totpDisableForm').classList.toggle('hidden', !state.totp);
    $('#totpSetup').classList.add('hidden');
}

$('#totpEnableBtn').addEventListener('click', async () => {
    try {
        const d = await api('totp_start');
        $('#totpSecret').textContent = d.secret;
        $('#totpLink').href = d.otpauth;
        $('#totpSetup').classList.remove('hidden');
        $('#totpCode').value = '';
        $('#totpCode').focus();
    } catch (err) { toast(err.message, 'err'); }
});

$('#totpEnableForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    try {
        await api('totp_enable', { form: new URLSearchParams({ code: $('#totpCode').value }) });
        state.totp = true;
        renderTotpStatus();
        toast('Two-factor authentication enabled', 'ok');
    } catch (err) { toast(err.message, 'err'); }
});

$('#totpDisableForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    try {
        await api('totp_disable', { form: new URLSearchParams({ current: $('#totpPw').value }) });
        state.totp = false;
        $('#totpPw').value = '';
        renderTotpStatus();
        toast('Two-factor authentication disabled', 'ok');
    } catch (err) { toast(err.message, 'err'); }
});

/* ---------- settings: passkeys ---------- */
async function loadPasskeys() {
    let d;
    try {
        d = await api('passkeys', { method: 'GET' });
    } catch (err) { toast(err.message, 'err'); return; }
    const box = $('#passkeyList');
    if (!d.length) {
        box.innerHTML = '<p class="muted" style="margin:0 0 8px">No passkeys yet.</p>';
        return;
    }
    box.innerHTML = d.map(r =>
        '<div class="ra-row" style="border-bottom:1px solid var(--outline-var);padding:8px 0">' +
        '<span style="flex:0 0 auto;color:var(--on-surface-var)">' + icon('key', 16) + '</span>' +
        '<span style="flex:0 0 auto;font-weight:500">' + esc(r.name) + '</span>' +
        '<span class="muted" style="flex:0 0 auto">' + esc(fmtRel(r.created_at)) + (r.last_used ? ' · used ' + esc(fmtRel(r.last_used)) : '') + '</span>' +
        '<button class="icon-btn sm danger" data-delpasskey="' + Number(r.id) + '" title="Delete passkey" aria-label="Delete passkey">' + icon('trash', 16) + '</button>' +
        '</div>').join('');
}

$('#addPasskeyBtn').addEventListener('click', async () => {
    const err = $('#passkeyError');
    err.classList.add('hidden');
    if (!passkeySupported()) {
        err.textContent = 'This browser does not support passkeys (HTTPS or localhost is required).';
        err.classList.remove('hidden');
        return;
    }
    const name = window.prompt('Name for this passkey (e.g. "iPhone 15"):') || '';
    if (!name) return;
    try {
        const d = await api('passkey_start');
        const cred = await navigator.credentials.create({
            publicKey: {
                challenge: b64urlToU8(d.challenge),
                rp: { id: d.rp_id, name: d.rp_name },
                user: { id: b64urlToU8(d.user.id), name: d.user.name, displayName: d.user.displayName },
                pubKeyCredParams: [
                    { type: 'public-key', alg: -7 },
                    { type: 'public-key', alg: -257 },
                    { type: 'public-key', alg: -8 }
                ],
                authenticatorSelection: { userVerification: 'preferred', residentKey: 'preferred' },
                attestation: 'none',
                timeout: 60000
            }
        });
        const resp = cred.response;
        await api('passkey_register', { json: {
            id: cred.id,
            name,
            client_data_json: bufToB64url(resp.clientDataJSON),
            attestation_object: bufToB64url(resp.attestationObject)
        }});
        toast('Passkey added', 'ok');
        await loadPasskeys();
    } catch (e) {
        if (e.name === 'NotAllowedError' || e.name === 'AbortError' || e.name === 'NotSupportedError') return;
        err.textContent = (e.data && e.data.error) || e.message || 'Could not add passkey';
        err.classList.remove('hidden');
    }
});

$('#passkeyList').addEventListener('click', e => {
    const b = e.target.closest('button[data-delpasskey]');
    if (!b) return;
    const id = Number(b.dataset.delpasskey);
    confirmDialog('Delete this passkey? You will not be able to sign in with it anymore.', 'Delete', async () => {
        try {
            await api('passkey_delete', { json: { id } });
            await loadPasskeys();
            toast('Passkey deleted', 'ok');
        } catch (err) { toast(err.message, 'err'); }
    });
});

/* ---------- settings: multipart uploads ---------- */
async function loadUploadsPanel() {
    let d;
    try {
        d = await api('uploads', { method: 'GET' });
    } catch (err) { return; }
    const tb = $('#uploadsTbody');
    tb.innerHTML = '';
    if (!d.rows.length) {
        tb.innerHTML = '<tr><td colspan="6"><div class="empty" style="padding:20px">' + icon('upload') + '<span>No in-progress uploads.</span></div></td></tr>';
        return;
    }
    d.rows.forEach(r => {
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td><span class="cellname">' + fileIcon(r.key) + '<span class="nm" title="' + esc(r.key) + '">' + esc(r.key) + '</span></span></td>' +
            '<td class="muted">' + esc(r.bucket_name || '-') + '</td>' +
            '<td class="muted">' + esc(r.username || '-') + '</td>' +
            '<td class="muted">' + esc(fmtBytes(r.size)) + '</td>' +
            '<td class="muted" title="' + esc(fmtTime(r.created_at)) + '">' + esc(fmtRel(r.created_at)) + '</td>' +
            '<td class="actions"><button data-uid="' + esc(r.upload_id) + '" class="btn btn-danger btn-sm"><span class="bi">' + icon('trash', 15) + '</span>Abort</button></td>';
        tb.appendChild(tr);
    });
}

$('#refreshUploadsBtn').addEventListener('click', loadUploadsPanel);

$('#uploadsTbody').addEventListener('click', e => {
    const btn = e.target.closest('button[data-uid]');
    if (!btn) return;
    const uid = btn.dataset.uid;
    confirmDialog('Abort this upload and delete its stored parts?', 'Abort', async () => {
        try {
            await api('uploads', { form: new URLSearchParams({ _sub: 'abort', upload_id: uid }) });
            toast('Upload aborted', 'ok');
            await loadUploadsPanel();
        } catch (err) { toast(err.message, 'err'); }
    });
});

$('#cleanupUploadsBtn').addEventListener('click', () => {
    confirmDialog('Abort all multipart uploads older than 7 days?', 'Cleanup', async () => {
        try {
            const d = await api('uploads', { form: new URLSearchParams({ _sub: 'cleanup', days: '7' }) });
            toast('Removed ' + d.removed + ' abandoned upload(s)', 'ok');
            await loadUploadsPanel();
        } catch (err) { toast(err.message, 'err'); }
    });
});

/* ---------- settings ---------- */
$('#saveLogsBtn').addEventListener('click', async () => {
    const err = $('#logsError');
    err.classList.add('hidden');
    const fd = new FormData();
    if ($('#logS3').checked) fd.append('log_s3', '1');
    if ($('#logAdmin').checked) fd.append('log_admin', '1');
    try {
        const d = await api('update_logs', { form: fd });
        applyLogSettings(d.log_s3, d.log_admin);
        toast('Log settings saved', 'ok');
    } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
    }
});

$('#profileForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const err = $('#profileError');
    err.classList.add('hidden');
    try {
        const d = await api('update_profile', { form: new FormData($('#profileForm')) });
        $('#headerUser').innerHTML = '<span class="chip-avatar">' + esc((d.username || 'a').charAt(0).toUpperCase()) + '</span><span>' + esc(d.username) + '</span>';
        toast('Username updated', 'ok');
    } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
    }
});

$('#settingsForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const err = $('#settingsError');
    err.classList.add('hidden');
    const f = $('#settingsForm');
    if (f.new.value !== f.new2.value) {
        err.textContent = 'New passwords do not match.';
        err.classList.remove('hidden');
        return;
    }
    const fd = new FormData(f);
    fd.delete('new2');
    try {
        await api('change_password', { form: fd });
        f.reset();
        toast('Password changed', 'ok');
    } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
    }
});

boot();
</script>
</body>
</html>
