<?php
/**
 * Admin template for Service Renewal Notifier
 * Variables available from service_renewal_notifier_output():
 *  $moduleLink, $version, $defaultTpl, $products, $emailTemplates,
 *  $services, $filterProduct, $filterMonth, $filterYear,
 *  $monthNames, $yearOptions
 */
if (!defined('WHMCS')) {
    die('Direct access not permitted.');
}

// Parse $moduleLink so we can split it into a base path + hidden query params.
// This prevents the GET form from wiping out "module=service_renewal_notifier"
// on submit (browsers replace the full query string when method=GET).
$_srn_parsed    = parse_url($moduleLink);
$_srn_formBase  = isset($_srn_parsed['path']) ? $_srn_parsed['path'] : 'addonmodules.php';
$_srn_linkParams = [];
if (!empty($_srn_parsed['query'])) {
    parse_str($_srn_parsed['query'], $_srn_linkParams);
}
// Build a clean module-only URL for AJAX (no filter params that change per request)
$_srn_ajaxUrl = $_srn_formBase . '?' . http_build_query($_srn_linkParams);
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap3.min.css">
<style>
    /* ---- Layout ---- */
    .srn-wrap { font-family: inherit; }
    .srn-header {
        background: linear-gradient(135deg, #1a3c5e 0%, #2d6da4 100%);
        color: #fff;
        padding: 18px 24px;
        border-radius: 6px 6px 0 0;
        margin-bottom: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .srn-header h2 { margin: 0; font-size: 20px; font-weight: 600; color: #fff; }
    .srn-header .badge-ver {
        background: rgba(255,255,255,0.2);
        border-radius: 12px;
        padding: 2px 10px;
        font-size: 12px;
    }

    /* ---- Filter Card ---- */
    .srn-filter-card {
        background: #fff;
        border: 1px solid #d9e3ee;
        border-top: 3px solid #2d6da4;
        border-radius: 0 0 6px 6px;
        padding: 20px 24px 14px;
        margin-bottom: 20px;
    }
    .srn-filter-card .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: flex-end;
    }
    .srn-filter-card .filter-group {
        display: flex;
        flex-direction: column;
        min-width: 180px;
        flex: 1 1 180px;
    }
    .srn-filter-card .filter-group label {
        font-size: 12px;
        font-weight: 600;
        color: #555;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: .4px;
    }
    .srn-filter-card select,
    .srn-filter-card .btn { height: 36px; }
    .srn-filter-card .btn-filter {
        background: #2d6da4;
        color: #fff;
        border: none;
        padding: 0 22px;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        transition: background .2s;
    }
    .srn-filter-card .btn-filter:hover { background: #1a4d7e; }
    .srn-filter-card .btn-reset {
        background: #f0f2f5;
        color: #555;
        border: 1px solid #ccc;
        padding: 0 16px;
        border-radius: 4px;
        cursor: pointer;
    }

    /* ---- Toolbar ---- */
    .srn-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 8px;
    }
    .srn-toolbar .result-info {
        font-size: 13px;
        color: #555;
    }
    .srn-toolbar .result-info strong { color: #222; }
    .btn-send-all {
        background: #27ae60;
        color: #fff;
        border: none;
        padding: 8px 18px;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: background .2s;
    }
    .btn-send-all:disabled { background: #aaa; cursor: not-allowed; }
    .btn-send-all:hover:not(:disabled) { background: #1e8449; }

    /* ---- Template selector ---- */
    .srn-tpl-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .srn-tpl-row label {
        font-size: 13px;
        font-weight: 600;
        color: #444;
        white-space: nowrap;
    }
    .srn-tpl-row select { height: 34px; min-width: 260px; }

    /* ---- Table ---- */
    .srn-table-card {
        background: #fff;
        border: 1px solid #d9e3ee;
        border-radius: 6px;
        padding: 18px 20px;
    }
    #srnTable thead th {
        background: #f7f9fc;
        color: #334;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .5px;
        border-bottom: 2px solid #dde4ee;
        white-space: nowrap;
    }
    #srnTable tbody tr:hover { background: #f0f6ff; }
    #srnTable td { vertical-align: middle; font-size: 13px; }

    /* Status badges */
    .badge-active    { background: #27ae60; color:#fff; border-radius:10px; padding:2px 9px; font-size:11px; }
    .badge-suspended { background: #e67e22; color:#fff; border-radius:10px; padding:2px 9px; font-size:11px; }
    .badge-other     { background: #95a5a6; color:#fff; border-radius:10px; padding:2px 9px; font-size:11px; }

    /* Due-date highlight */
    .due-today    { color: #e74c3c; font-weight: 700; }
    .due-soon     { color: #e67e22; font-weight: 600; }
    .due-normal   { color: #27ae60; }
    .due-overdue  { color: #c0392b; font-style: italic; }

    /* Checkbox column */
    .srn-check { width: 36px; text-align: center; }
    #selectAll { cursor: pointer; }

    /* Send btn */
    .btn-send-single {
        background: #2d6da4;
        color: #fff;
        border: none;
        padding: 5px 12px;
        border-radius: 4px;
        font-size: 12px;
        cursor: pointer;
        white-space: nowrap;
        transition: background .2s;
    }
    .btn-send-single:hover { background: #1a4d7e; }
    .btn-send-single .spin { display: none; }
    .btn-send-single.sending .spin  { display: inline-block; }
    .btn-send-single.sending .label { display: none; }

    /* Toast */
    #srn-toast {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 9999;
        min-width: 280px;
        max-width: 400px;
        border-radius: 6px;
        padding: 14px 18px;
        font-size: 14px;
        color: #fff;
        box-shadow: 0 4px 16px rgba(0,0,0,.25);
        display: none;
        animation: fadeIn .3s ease;
    }
    #srn-toast.success { background: #27ae60; }
    #srn-toast.error   { background: #e74c3c; }
    #srn-toast.info    { background: #2d6da4; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

    /* Empty state */
    .srn-empty {
        text-align: center;
        padding: 50px 20px;
        color: #888;
    }
    .srn-empty .icon { font-size: 48px; margin-bottom: 10px; }

    /* SMTP debug warning banner */
    .srn-smtp-warn {
        background: #fff8e1;
        border: 1px solid #ffe082;
        border-left: 4px solid #f9a825;
        border-radius: 4px;
        padding: 10px 16px;
        margin-bottom: 14px;
        font-size: 13px;
        color: #5d4037;
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .srn-smtp-warn i { color: #f9a825; font-size: 16px; margin-top: 1px; flex-shrink: 0; }
    .srn-smtp-warn a { color: #1565c0; }
</style>

<div class="srn-wrap">

    <!-- Header -->
    <div class="srn-header">
        <h2><i class="fa fa-refresh"></i> &nbsp;Service Renewal Notifier</h2>
        <span class="badge-ver">v<?php echo htmlspecialchars($version); ?></span>
    </div>

    <?php if ($smtpDebugEnabled): ?>
    <!-- SMTP Debug warning -->
    <div class="srn-smtp-warn" style="margin-top:10px;">
        <i class="fa fa-exclamation-triangle"></i>
        <div>
            <strong>SMTP Debug logging is ON</strong> — WHMCS is writing every SMTP handshake line to the
            Activity Log. This plugin suppresses those entries during its own sends, but emails triggered
            elsewhere will still be logged verbosely.<br>
            To disable globally: <strong>Setup &rsaquo; General Settings &rsaquo; Mail</strong> &rarr;
            set <em>SMTP Debug Level</em> to <strong>Off / 0</strong>
            (or add <code>$mail_debug_level = 0;</code> to <code>configuration.php</code>).
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="srn-filter-card">
        <!--
            Action = base script only (e.g. addonmodules.php).
            Hidden inputs carry the existing query params (e.g. module=service_renewal_notifier)
            so they are not lost when the browser rebuilds the query string on GET submit.
        -->
        <form method="GET" action="<?php echo htmlspecialchars($_srn_formBase); ?>" id="filterForm">
            <?php foreach ($_srn_linkParams as $_k => $_v): ?>
                <input type="hidden"
                       name="<?php echo htmlspecialchars($_k); ?>"
                       value="<?php echo htmlspecialchars($_v); ?>">
            <?php endforeach; ?>
            <div class="filter-row">

                <!-- Category / Group -->
                <div class="filter-group">
                    <label><i class="fa fa-folder-open"></i> &nbsp;Category / Group</label>
                    <select name="group_id" id="filterGroup" class="form-control" onchange="srnFilterProducts()">
                        <option value="0">-- All Categories --</option>
                        <?php foreach ($productGroups as $g): ?>
                            <option value="<?php echo (int)$g->id; ?>"
                                <?php echo ($filterGroup === (int)$g->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Product / Service -->
                <div class="filter-group">
                    <label><i class="fa fa-cube"></i> &nbsp;Service / Product</label>
                    <select name="product_id" id="filterProduct" class="form-control">
                        <option value="0">-- All Products --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo (int)$p->id; ?>"
                                      data-gid="<?php echo (int)$p->gid; ?>"
                                <?php echo ($filterProduct === (int)$p->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Month -->
                <div class="filter-group">
                    <label><i class="fa fa-calendar"></i> &nbsp;Renewal Month</label>
                    <select name="month" class="form-control">
                        <?php foreach ($monthNames as $num => $name): ?>
                            <option value="<?php echo $num; ?>"
                                <?php echo ($filterMonth === $num) ? 'selected' : ''; ?>>
                                <?php echo $name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Year -->
                <div class="filter-group">
                    <label><i class="fa fa-calendar-o"></i> &nbsp;Year</label>
                    <select name="year" class="form-control">
                        <?php foreach ($yearOptions as $y): ?>
                            <option value="<?php echo $y; ?>"
                                <?php echo ($filterYear === $y) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Buttons -->
                <div class="filter-group" style="max-width:200px;">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-filter">
                            <i class="fa fa-search"></i> Filter
                        </button>
                        <button type="button" class="btn btn-reset" onclick="resetFilter()">
                            <i class="fa fa-times"></i> Reset
                        </button>
                    </div>
                </div>

            </div>
        </form>
    </div><!-- /filter-card -->

    <!-- Table Card -->
    <div class="srn-table-card">

        <!-- Email template selector + Send All button -->
        <div class="srn-tpl-row">
            <label for="emailTemplate"><i class="fa fa-envelope-o"></i> &nbsp;Email Template:</label>
            <select id="emailTemplate" class="form-control">
                <option value="">-- Select Template --</option>
                <?php
                // Group templates by type for easier browsing
                $tplGroups = [];
                foreach ($emailTemplates as $tpl) {
                    $tplGroups[$tpl->type][] = $tpl;
                }
                foreach ($tplGroups as $groupType => $groupTpls):
                ?>
                    <optgroup label="<?php echo htmlspecialchars(ucfirst($groupType)); ?>">
                        <?php foreach ($groupTpls as $tpl): ?>
                            <option value="<?php echo htmlspecialchars($tpl->name); ?>"
                                <?php echo ($tpl->name === $defaultTpl) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tpl->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <input type="text" id="emailTemplateCustom" class="form-control"
                   placeholder="Or type template name…"
                   style="min-width:200px;height:34px;"
                   value="<?php echo htmlspecialchars($defaultTpl); ?>">
        </div>

        <?php if (count((array)$services) === 0): ?>

            <div class="srn-empty">
                <div class="icon"><i class="fa fa-inbox"></i></div>
                <h4>No services found</h4>
                <p>No active/suspended services are due for renewal in
                    <strong><?php echo $monthNames[$filterMonth] . ' ' . $filterYear; ?></strong>
                    <?php if ($filterProduct > 0): ?>
                        for the selected product.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <!-- Toolbar -->
            <div class="srn-toolbar">
                <div class="result-info">
                    Showing <strong><?php echo count((array)$services); ?></strong> service(s) due in
                    <strong><?php echo $monthNames[$filterMonth] . ' ' . $filterYear; ?></strong>
                </div>
                <button class="btn-send-all" id="btnSendAll" onclick="sendAll()">
                    <i class="fa fa-paper-plane"></i>
                    Send to All Selected
                </button>
            </div>

            <!-- Data Table -->
            <table id="srnTable" class="table table-bordered table-hover" style="width:100%">
                <thead>
                    <tr>
                        <th class="srn-check">
                            <input type="checkbox" id="selectAll" title="Select all">
                        </th>
                        <th>#</th>
                        <th>Client</th>
                        <th>Email</th>
                        <th>Product</th>
                        <th>Domain / Service</th>
                        <th>Billing Cycle</th>
                        <th>Amount</th>
                        <th>Next Due Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $svc):
                        $today    = new DateTime(date('Y-m-d'));
                        $dueDate  = new DateTime($svc->nextduedate);
                        $diff     = (int)$today->diff($dueDate)->format('%r%a'); // negative = overdue

                        if ($diff < 0)        $dueCls = 'due-overdue';
                        elseif ($diff === 0)   $dueCls = 'due-today';
                        elseif ($diff <= 7)    $dueCls = 'due-soon';
                        else                   $dueCls = 'due-normal';

                        $statusBadge = strtolower($svc->status) === 'active'    ? 'badge-active'
                                     : (strtolower($svc->status) === 'suspended' ? 'badge-suspended' : 'badge-other');
                    ?>
                    <tr>
                        <td class="srn-check">
                            <input type="checkbox" class="row-check"
                                   data-id="<?php echo (int)$svc->hosting_id; ?>">
                        </td>
                        <td>
                            <a href="clientsservices.php?userid=<?php echo (int)$svc->client_id; ?>"
                               target="_blank" title="View client">
                                <?php echo (int)$svc->client_id; ?>
                            </a>
                        </td>
                        <td>
                            <a href="clientssummary.php?userid=<?php echo (int)$svc->client_id; ?>"
                               target="_blank">
                                <?php echo htmlspecialchars($svc->firstname . ' ' . $svc->lastname); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($svc->email); ?></td>
                        <td><?php echo htmlspecialchars($svc->product_name); ?></td>
                        <td>
                            <?php if (!empty($svc->domain)): ?>
                                <code style="font-size:12px;"><?php echo htmlspecialchars($svc->domain); ?></code>
                            <?php else: ?>
                                <span style="color:#aaa;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars(ucfirst($svc->billingcycle)); ?></td>
                        <td><?php echo !empty($svc->amount) ? '$' . number_format((float)$svc->amount, 2) : '—'; ?></td>
                        <td>
                            <span class="<?php echo $dueCls; ?>">
                                <?php echo date('d M Y', strtotime($svc->nextduedate)); ?>
                            </span>
                            <?php if ($diff < 0): ?>
                                <br><small style="color:#c0392b;">Overdue <?php echo abs($diff); ?>d</small>
                            <?php elseif ($diff === 0): ?>
                                <br><small style="color:#e74c3c;">Due today!</small>
                            <?php elseif ($diff <= 7): ?>
                                <br><small style="color:#e67e22;">in <?php echo $diff; ?> day<?php echo $diff !== 1 ? 's' : ''; ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="<?php echo $statusBadge; ?>"><?php echo htmlspecialchars($svc->status); ?></span></td>
                        <td>
                            <button class="btn-send-single"
                                    data-id="<?php echo (int)$svc->hosting_id; ?>"
                                    onclick="sendSingle(this, <?php echo (int)$svc->hosting_id; ?>)">
                                <span class="spin"><i class="fa fa-spinner fa-spin"></i></span>
                                <span class="label"><i class="fa fa-paper-plane"></i> Send Email</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div><!-- /table-card -->

    <!-- ================================================================
         Variables Reference + Custom Variables Editor
         ================================================================ -->
    <div class="srn-table-card" style="margin-top:20px;">

        <!-- Toggle header -->
        <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;"
             onclick="togglePanel('varPanel')">
            <h4 style="margin:0;font-size:15px;color:#1a3c5e;">
                <i class="fa fa-code"></i> &nbsp;Template Variables &amp; Custom Variables
            </h4>
            <span id="varPanelIcon" style="color:#2d6da4;font-size:18px;">&#9660;</span>
        </div>

        <div id="varPanel" style="display:none;margin-top:16px;">
            <div style="display:flex;flex-wrap:wrap;gap:20px;">

                <!-- LEFT: built-in variables reference -->
                <div style="flex:1 1 320px;min-width:280px;">
                    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#333;">
                        Built-in Variables <small style="font-weight:normal;color:#777;">(copy &amp; paste into your template)</small>
                    </p>
                    <table class="table table-condensed table-bordered" style="font-size:12px;margin:0;">
                        <thead><tr style="background:#f7f9fc;">
                            <th>Variable</th><th>Example Value</th><th>Description</th>
                        </tr></thead>
                        <tbody>
                            <tr><td><code>{$client_name}</code></td><td>John Doe</td><td>Full name</td></tr>
                            <tr><td><code>{$client_firstname}</code></td><td>John</td><td>First name</td></tr>
                            <tr><td><code>{$client_lastname}</code></td><td>Doe</td><td>Last name</td></tr>
                            <tr><td><code>{$client_email}</code></td><td>john@example.com</td><td>Email address</td></tr>
                            <tr><td><code>{$client_id}</code></td><td>42</td><td>WHMCS client ID</td></tr>
                            <tr><td><code>{$service_product_name}</code></td><td>Microsoft 365 Business</td><td>Product / plan name</td></tr>
                            <tr><td><code>{$service_domain}</code></td><td>example.com</td><td>Service domain</td></tr>
                            <tr><td><code>{$service_next_due_date}</code></td><td>20/08/2026</td><td>Renewal due date</td></tr>
                            <tr><td><code>{$service_recurring_amount}</code></td><td>13.28</td><td>Renewal amount</td></tr>
                            <tr><td><code>{$service_billing_cycle}</code></td><td>Annually</td><td>Billing cycle</td></tr>
                            <tr><td><code>{$service_status}</code></td><td>Active</td><td>Service status</td></tr>
                            <tr><td><code>{$service_id}</code></td><td>101</td><td>WHMCS service ID</td></tr>
                            <tr><td><code>{$current_date}</code></td><td><?php echo date('d/m/Y'); ?></td><td>Today's date</td></tr>
                            <tr><td><code>{$current_year}</code></td><td><?php echo date('Y'); ?></td><td>Current year</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- RIGHT: custom variables editor -->
                <div style="flex:1 1 320px;min-width:280px;">
                    <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#333;">
                        Custom Variables
                        <small style="font-weight:normal;color:#777;">— define your own, one per line: <code>{$var_name} = value</code></small>
                    </p>
                    <textarea id="customVarsTextarea"
                              rows="10"
                              class="form-control"
                              style="font-family:monospace;font-size:12px;resize:vertical;"
                              placeholder="# Lines starting with # are comments&#10;{$company_name} = CloudMinister&#10;{$company_phone} = +91-XXXXXXXXXX&#10;{$company_email} = support@cloudminister.com&#10;{$gst_note} = + 18% GST"><?php echo htmlspecialchars($customVarsRaw); ?></textarea>
                    <div style="margin-top:8px;display:flex;align-items:center;gap:10px;">
                        <button onclick="saveCustomVars()" class="btn btn-primary btn-sm">
                            <i class="fa fa-save"></i> Save Variables
                        </button>
                        <span id="customVarsSaveMsg" style="font-size:12px;display:none;"></span>
                    </div>
                    <p style="margin:10px 0 0;font-size:11px;color:#888;">
                        After saving, these variables work in <strong>any</strong> email template body.<br>
                        Example: use <code>{$company_name}</code> in your template and it will be replaced with <em>CloudMinister</em>.
                    </p>
                </div>

            </div><!-- /flex -->
        </div><!-- /varPanel -->
    </div>

    <!-- ================================================================
         Recent Sends Log
         ================================================================ -->
    <div class="srn-table-card" style="margin-top:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <h4 style="margin:0;font-size:15px;color:#1a3c5e;">
                <i class="fa fa-history"></i> &nbsp;Recent Email Sends
                <small style="font-size:12px;color:#888;">(last 20 — also visible in Admin &rsaquo; Logs &rsaquo; Activity Log)</small>
            </h4>
        </div>

        <?php if (empty((array)$recentLogs)): ?>
            <p style="color:#888;font-size:13px;padding:12px 0;">
                No emails sent yet. Sent emails will appear here.
            </p>
        <?php else: ?>
        <table class="table table-bordered table-condensed" style="font-size:12px;margin:0;">
            <thead>
                <tr style="background:#f7f9fc;">
                    <th>#</th>
                    <th>Client</th>
                    <th>Email Address</th>
                    <th>Domain / Service</th>
                    <th>Template Used</th>
                    <th>Sent By</th>
                    <th>Sent At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td>
                        <a href="clientssummary.php?userid=<?php echo (int)$log->client_id; ?>"
                           target="_blank" title="View client">
                            <?php echo (int)$log->client_id; ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($log->client_name ?: ('Client #' . $log->client_id)); ?></td>
                    <td><?php echo htmlspecialchars($log->client_email ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($log->domain ?: '—'); ?></td>
                    <td><em><?php echo htmlspecialchars($log->template_name); ?></em></td>
                    <td><?php echo htmlspecialchars($log->sent_by); ?></td>
                    <td style="white-space:nowrap;"><?php echo htmlspecialchars($log->sent_at); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div><!-- /srn-wrap -->

<!-- Toast notification -->
<div id="srn-toast"></div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap3.min.js"></script>
<script>
(function ($) {
    'use strict';

    // Init DataTable (only when there are rows)
    if ($('#srnTable tbody tr').length) {
        $('#srnTable').DataTable({
            pageLength: 25,
            order: [[8, 'asc']],   // sort by Next Due Date
            columnDefs: [
                { orderable: false, targets: [0, 10] }
            ],
            language: {
                search:      'Search:',
                lengthMenu:  'Show _MENU_ entries',
                info:        'Showing _START_ to _END_ of _TOTAL_ services',
            }
        });
    }

    // Select-all checkbox
    $('#selectAll').on('change', function () {
        $('.row-check').prop('checked', this.checked);
    });

    // Sync selectAll state
    $(document).on('change', '.row-check', function () {
        var all  = $('.row-check').length;
        var chkd = $('.row-check:checked').length;
        $('#selectAll').prop('checked', all === chkd);
        $('#selectAll').prop('indeterminate', chkd > 0 && chkd < all);
    });

    // Resolve effective template name
    window.getTemplateName = function () {
        var sel    = $('#emailTemplate').val();
        var custom = $('#emailTemplateCustom').val().trim();
        return custom || sel || '';
    };

    // When dropdown changes, update text box
    $('#emailTemplate').on('change', function () {
        $('#emailTemplateCustom').val($(this).val());
    });

    // Send single
    window.sendSingle = function (btn, hostingId) {
        var tpl = getTemplateName();
        if (!tpl) {
            showToast('Please select or type an email template name.', 'error');
            return;
        }
        $(btn).addClass('sending').prop('disabled', true);

        $.post('<?php echo addslashes($_srn_ajaxUrl); ?>', {
            action:        'send_email',
            hosting_id:    hostingId,
            template_name: tpl
        }, function (res) {
            $(btn).removeClass('sending').prop('disabled', false);
            if (res.success) {
                // Show exactly who received the email — client name + email address
                var recipient = res.client_name
                    ? res.client_name + ' &lt;' + res.client_email + '&gt;'
                    : 'Service #' + hostingId;
                showToast('<i class="fa fa-check-circle"></i> &nbsp;Email sent to: <strong>' + recipient + '</strong>', 'success');
                $(btn).css('background', '#27ae60');
                $(btn).find('.label').html('<i class="fa fa-check"></i> Sent');
                // Reload log panel after short delay so new entry appears
                setTimeout(function () { location.reload(); }, 2500);
            } else {
                showToast('<i class="fa fa-times-circle"></i> &nbsp;Error: ' + res.message, 'error');
            }
        }, 'json').fail(function () {
            $(btn).removeClass('sending').prop('disabled', false);
            showToast('Request failed. Check server logs.', 'error');
        });
    };

    // Send all selected
    window.sendAll = function () {
        var tpl = getTemplateName();
        if (!tpl) {
            showToast('Please select or type an email template name.', 'error');
            return;
        }

        var ids = [];
        $('.row-check:checked').each(function () {
            ids.push($(this).data('id'));
        });

        if (ids.length === 0) {
            showToast('No services selected. Use the checkboxes to select services.', 'info');
            return;
        }

        if (!confirm('Send "' + tpl + '" to ' + ids.length + ' client(s)?')) return;

        $('#btnSendAll').prop('disabled', true).html(
            '<i class="fa fa-spinner fa-spin"></i> Sending...'
        );

        $.post('<?php echo addslashes($_srn_ajaxUrl); ?>', {
            action:        'send_bulk',
            ids:           JSON.stringify(ids),
            template_name: tpl
        }, function (res) {
            $('#btnSendAll').prop('disabled', false).html(
                '<i class="fa fa-paper-plane"></i> Send to All Selected'
            );
            if (res.success) {
                var msg = '<i class="fa fa-check-circle"></i> &nbsp;' + res.message;
                if (res.errors && res.errors.length) {
                    msg += '<br><small>Failures: ' + res.errors.join('; ') + '</small>';
                    showToast(msg, res.failed > 0 ? 'error' : 'success');
                } else {
                    showToast(msg, 'success');
                }
                setTimeout(function () { location.reload(); }, 3000);
            } else {
                showToast('Error: ' + res.message, 'error');
            }
        }, 'json').fail(function () {
            $('#btnSendAll').prop('disabled', false).html(
                '<i class="fa fa-paper-plane"></i> Send to All Selected'
            );
            showToast('Bulk request failed. Check server logs.', 'error');
        });
    };

    // Toggle collapsible panel
    window.togglePanel = function (id) {
        var $p = $('#' + id);
        var $icon = $('#' + id + 'Icon');
        if ($p.is(':visible')) {
            $p.slideUp(200);
            $icon.html('&#9660;');
        } else {
            $p.slideDown(200);
            $icon.html('&#9650;');
        }
    };

    // Save custom variables
    window.saveCustomVars = function () {
        var raw = $('#customVarsTextarea').val();
        var $msg = $('#customVarsSaveMsg');
        $msg.hide();

        $.post('<?php echo addslashes($_srn_ajaxUrl); ?>', {
            action:      'save_custom_vars',
            custom_vars: raw
        }, function (res) {
            $msg.show()
                .css('color', res.success ? '#27ae60' : '#e74c3c')
                .html(res.success
                    ? '<i class="fa fa-check"></i> ' + res.message
                    : '<i class="fa fa-times"></i> ' + res.message);
        }, 'json').fail(function () {
            $msg.show().css('color', '#e74c3c').html('Save failed.');
        });
    };

    // Filter product dropdown based on selected group
    window.srnFilterProducts = function () {
        var gid     = parseInt($('#filterGroup').val(), 10) || 0;
        var $sel    = $('#filterProduct');
        var current = parseInt($sel.val(), 10) || 0;

        $sel.find('option').each(function () {
            var optGid = parseInt($(this).data('gid'), 10) || 0;
            var $opt   = $(this);
            if ($opt.val() === '0' || gid === 0 || optGid === gid) {
                $opt.show().prop('disabled', false);
            } else {
                $opt.hide().prop('disabled', true);
            }
        });

        // If the currently selected product no longer belongs to the new group, reset it
        var $chosen = $sel.find('option[value="' + current + '"]');
        if (current !== 0 && $chosen.prop('disabled')) {
            $sel.val('0');
        }
    };

    // Run on page load to restore state when group_id is already set in URL
    srnFilterProducts();

    // Reset filter — rebuild URL from the clean module-only base
    window.resetFilter = function () {
        var today = new Date();
        var base  = '<?php echo addslashes($_srn_ajaxUrl); ?>';
        window.location.href = base +
            '&group_id=0' +
            '&product_id=0' +
            '&month='  + (today.getMonth() + 1) +
            '&year='   + today.getFullYear();
    };

    // Toast
    window.showToast = function (msg, type) {
        var $t = $('#srn-toast');
        $t.removeClass('success error info')
          .addClass(type)
          .html(msg)
          .fadeIn(200);
        clearTimeout($t.data('timer'));
        $t.data('timer', setTimeout(function () { $t.fadeOut(400); }, 4000));
    };

}(jQuery));
</script>
