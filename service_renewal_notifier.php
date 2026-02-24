<?php
/**
 * WHMCS Service Renewal Notifier Addon Module
 *
 * Filter services by product type and renewal month, then send
 * email notifications to clients directly from the admin area.
 *
 * @package    service_renewal_notifier
 * @author     WHMCS Addon
 * @version    1.1.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

// ---------------------------------------------------------------------------
// Module Meta
// ---------------------------------------------------------------------------

function service_renewal_notifier_config()
{
    return [
        'name'        => 'Service Renewal Notifier',
        'description' => 'Filter services by product and renewal month, then send email notifications to clients.',
        'version'     => '1.1.0',
        'author'      => 'Custom',
        'language'    => 'english',
        'fields'      => [
            'default_template' => [
                'FriendlyName' => 'Default Email Template',
                'Type'         => 'text',
                'Size'         => '50',
                'Default'      => 'Service Renewal Notice',
                'Description'  => 'Email template name used for renewal notifications.',
            ],
            'days_before' => [
                'FriendlyName' => 'Default Days-Before Notice',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '7',
                'Description'  => 'Informational label shown in the module (no automatic sending).',
            ],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Activate / Deactivate
// ---------------------------------------------------------------------------

function service_renewal_notifier_activate()
{
    try {
        Capsule::schema()->create('mod_srn_log', function ($table) {
            $table->increments('id');
            $table->integer('hosting_id')->unsigned();
            $table->integer('client_id')->unsigned();
            $table->string('client_name', 150)->default('');
            $table->string('client_email', 150)->default('');
            $table->string('domain', 150)->default('');
            $table->string('template_name', 100);
            $table->string('sent_by', 100)->default('admin');
            $table->timestamp('sent_at')->useCurrent();
        });
    } catch (\Exception $e) {
        // Table may already exist — run migration to add any missing columns
        _srn_ensure_schema();
    }

    return ['status' => 'success', 'description' => 'Service Renewal Notifier activated.'];
}

function service_renewal_notifier_deactivate()
{
    try {
        Capsule::schema()->dropIfExists('mod_srn_log');
    } catch (\Exception $e) {
        // Ignore
    }

    return ['status' => 'success', 'description' => 'Service Renewal Notifier deactivated.'];
}

// ---------------------------------------------------------------------------
// Schema migration — safe to call on every request.
// Creates the table if it does not exist, then adds any columns that were
// missing in the v1.0.0 schema.
// ---------------------------------------------------------------------------
function _srn_ensure_schema()
{
    // Create the full table if it has never been created (e.g. the addon was
    // activated before this version, or the table was dropped manually).
    if (!Capsule::schema()->hasTable('mod_srn_log')) {
        try {
            Capsule::schema()->create('mod_srn_log', function ($table) {
                $table->increments('id');
                $table->integer('hosting_id')->unsigned();
                $table->integer('client_id')->unsigned();
                $table->string('client_name', 150)->default('');
                $table->string('client_email', 150)->default('');
                $table->string('domain', 150)->default('');
                $table->string('template_name', 100);
                $table->string('sent_by', 100)->default('admin');
                $table->timestamp('sent_at')->useCurrent();
            });
        } catch (\Exception $e) {
            logActivity('SRN: Failed to auto-create mod_srn_log table: ' . $e->getMessage());
        }
        return; // Fresh table already has every column — no migration needed
    }

    // Table exists — add any columns that are missing from the v1.0.0 schema
    $newColumns = [
        'client_name'  => "ALTER TABLE `mod_srn_log` ADD COLUMN `client_name`  VARCHAR(150) NOT NULL DEFAULT '' AFTER `client_id`",
        'client_email' => "ALTER TABLE `mod_srn_log` ADD COLUMN `client_email` VARCHAR(150) NOT NULL DEFAULT '' AFTER `client_name`",
        'domain'       => "ALTER TABLE `mod_srn_log` ADD COLUMN `domain`       VARCHAR(150) NOT NULL DEFAULT '' AFTER `client_email`",
    ];

    foreach ($newColumns as $col => $sql) {
        try {
            if (!Capsule::schema()->hasColumn('mod_srn_log', $col)) {
                Capsule::statement($sql);
            }
        } catch (\Exception $e) {
            // Ignore — column may have been added by a concurrent request
        }
    }
}

// ---------------------------------------------------------------------------
// Admin Output
// ---------------------------------------------------------------------------

function service_renewal_notifier_output($vars)
{
    // Migrate schema for existing installs that pre-date v1.1.0
    _srn_ensure_schema();

    $moduleLink = $vars['modulelink'];
    $version    = $vars['version'];
    $defaultTpl = isset($vars['default_template']) ? $vars['default_template'] : 'Service Renewal Notice';

    // -----------------------------------------------------------------------
    // AJAX: send single email
    // -----------------------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'send_email') {
        _srn_handle_send_email($defaultTpl);
        exit;
    }

    // -----------------------------------------------------------------------
    // AJAX: send bulk emails
    // -----------------------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'send_bulk') {
        _srn_handle_send_bulk($defaultTpl);
        exit;
    }

    // -----------------------------------------------------------------------
    // AJAX: save custom variables
    // -----------------------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'save_custom_vars') {
        _srn_handle_save_custom_vars();
        exit;
    }

    // -----------------------------------------------------------------------
    // Fetch filter values
    // -----------------------------------------------------------------------
    $filterGroup   = isset($_GET['group_id'])   ? (int) $_GET['group_id']   : 0;
    $filterProduct = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $filterMonth   = isset($_GET['month'])      ? (int) $_GET['month']      : (int) date('n');
    $filterYear    = isset($_GET['year'])        ? (int) $_GET['year']        : (int) date('Y');

    if ($filterMonth < 1 || $filterMonth > 12) {
        $filterMonth = (int) date('n');
    }
    if ($filterYear < 2000 || $filterYear > 2099) {
        $filterYear = (int) date('Y');
    }

    // -----------------------------------------------------------------------
    // Fetch product groups (categories) for the dropdown
    // -----------------------------------------------------------------------
    $productGroups = Capsule::table('tblproductgroups')
        ->select('id', 'name')
        ->orderBy('name')
        ->get();

    // -----------------------------------------------------------------------
    // Fetch products for the dropdown (with gid so JS can filter by group)
    // -----------------------------------------------------------------------
    $products = Capsule::table('tblproducts')
        ->select('id', 'name', 'gid')
        ->orderBy('gid')
        ->orderBy('name')
        ->get();

    // -----------------------------------------------------------------------
    // Fetch email templates — all types, grouped in the UI
    // -----------------------------------------------------------------------
    $emailTemplates = Capsule::table('tblemailtemplates')
        ->select('id', 'name', 'type')
        ->orderBy('type')
        ->orderBy('name')
        ->get();

    // -----------------------------------------------------------------------
    // Fetch services due in selected month/year
    // -----------------------------------------------------------------------
    $startDate = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
    $endDate   = date('Y-m-t', mktime(0, 0, 0, $filterMonth, 1, $filterYear));

    $query = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->select(
            'h.id as hosting_id',
            'h.userid as client_id',
            'h.domain',
            'h.nextduedate',
            'h.domainstatus as status',
            'h.amount',
            'h.billingcycle',
            'p.id as product_id',
            'p.name as product_name',
            'p.gid as product_gid',
            'c.firstname',
            'c.lastname',
            'c.email'
        )
        ->whereBetween('h.nextduedate', [$startDate, $endDate])
        ->whereIn('h.domainstatus', ['Active', 'Suspended'])
        ->orderBy('h.nextduedate');

    // Group filter takes priority; product filter narrows further within the group
    if ($filterGroup > 0) {
        $query->where('p.gid', $filterGroup);
    }
    if ($filterProduct > 0) {
        $query->where('h.packageid', $filterProduct);
    }

    $services = $query->get();

    // -----------------------------------------------------------------------
    // Recent sends log (last 20 entries, newest first)
    // -----------------------------------------------------------------------
    $recentLogs = [];
    if (Capsule::schema()->hasTable('mod_srn_log')) {
        $recentLogs = Capsule::table('mod_srn_log')
            ->orderBy('sent_at', 'desc')
            ->limit(20)
            ->get();
    }

    // -----------------------------------------------------------------------
    // Detect whether WHMCS SMTP debug logging is enabled
    // Used to display a warning banner in the UI.
    // -----------------------------------------------------------------------
    $smtpDebugEnabled = false;
    try {
        $smtpDebugVal     = Capsule::table('tblconfiguration')
            ->where('setting', 'MailSMTPDebug')
            ->value('value');
        $smtpDebugEnabled = ($smtpDebugVal !== null && (int) $smtpDebugVal > 0);
    } catch (\Exception $e) { /* ignore */ }

    // -----------------------------------------------------------------------
    // Custom variables (stored in tbladdonmodules)
    // -----------------------------------------------------------------------
    $customVarsRaw = Capsule::table('tbladdonmodules')
        ->where('module', 'service_renewal_notifier')
        ->where('setting', 'custom_variables')
        ->value('value');
    $customVarsRaw = $customVarsRaw ?: '';

    // -----------------------------------------------------------------------
    // Build year options / month names
    // -----------------------------------------------------------------------
    $currentYear = (int) date('Y');
    $yearOptions = range($currentYear - 1, $currentYear + 2);
    $monthNames  = [
        1 => 'January', 2 => 'February', 3 => 'March',
        4 => 'April',   5 => 'May',       6 => 'June',
        7 => 'July',    8 => 'August',    9 => 'September',
        10 => 'October', 11 => 'November', 12 => 'December',
    ];

    ob_start();
    include __DIR__ . '/templates/admin/index.php';
    echo ob_get_clean();
}

// ---------------------------------------------------------------------------
// Helper: Send single email (AJAX handler)
// ---------------------------------------------------------------------------
function _srn_handle_send_email($defaultTpl)
{
    header('Content-Type: application/json');

    $hostingId    = isset($_POST['hosting_id'])    ? (int) $_POST['hosting_id']    : 0;
    $templateName = isset($_POST['template_name']) ? trim($_POST['template_name']) : $defaultTpl;

    if ($hostingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid service ID.']);
        return;
    }

    // Look up service + client in a single query
    $svc = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->select(
            'h.id as hosting_id',
            'h.userid as client_id',
            'h.domain',
            'h.nextduedate',
            'p.name as product_name',
            'c.firstname',
            'c.lastname',
            'c.email as client_email'
        )
        ->where('h.id', $hostingId)
        ->first();

    if (!$svc) {
        echo json_encode(['success' => false, 'message' => 'Service not found.']);
        return;
    }

    $result = _srn_send_notification(
        $svc->client_id,
        $svc->hosting_id,
        $templateName
    );

    if ($result['success']) {
        _srn_log_sent(
            $svc->hosting_id,
            $svc->client_id,
            $svc->firstname . ' ' . $svc->lastname,
            $svc->client_email,
            $svc->domain,
            $templateName
        );
        $result['client_name']  = $svc->firstname . ' ' . $svc->lastname;
        $result['client_email'] = $svc->client_email;
    }

    echo json_encode($result);
}

// ---------------------------------------------------------------------------
// Helper: Send bulk emails (AJAX handler)
// ---------------------------------------------------------------------------
function _srn_handle_send_bulk($defaultTpl)
{
    header('Content-Type: application/json');

    $ids          = isset($_POST['ids'])           ? json_decode($_POST['ids'], true) : [];
    $templateName = isset($_POST['template_name']) ? trim($_POST['template_name'])    : $defaultTpl;

    if (empty($ids) || !is_array($ids)) {
        echo json_encode(['success' => false, 'message' => 'No services selected.']);
        return;
    }

    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));

    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'No valid service IDs provided.']);
        return;
    }

    // Fetch all requested services with client info in one query
    $services = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->select(
            'h.id as hosting_id',
            'h.userid as client_id',
            'h.domain',
            'c.firstname',
            'c.lastname',
            'c.email as client_email'
        )
        ->whereIn('h.id', $ids)
        ->get();

    // Index fetched services by hosting_id so we can cross-check
    // that every requested ID was actually found (not fabricated)
    $fetched = [];
    foreach ($services as $s) {
        $fetched[$s->hosting_id] = $s;
    }

    $sent   = 0;
    $failed = 0;
    $errors = [];

    foreach ($ids as $hid) {
        if (!isset($fetched[$hid])) {
            $failed++;
            $errors[] = "Service #{$hid}: not found.";
            continue;
        }
        $svc = $fetched[$hid];

        $r = _srn_send_notification($svc->client_id, $svc->hosting_id, $templateName);

        if ($r['success']) {
            _srn_log_sent(
                $svc->hosting_id,
                $svc->client_id,
                $svc->firstname . ' ' . $svc->lastname,
                $svc->client_email,
                $svc->domain,
                $templateName
            );
            $sent++;
        } else {
            $failed++;
            $clientName = $svc->firstname . ' ' . $svc->lastname;
            $errors[] = "{$clientName} (Service #{$svc->hosting_id}): " . $r['message'];
        }
    }

    echo json_encode([
        'success' => true,
        'sent'    => $sent,
        'failed'  => $failed,
        'message' => "Done — Sent: {$sent}, Failed: {$failed}.",
        'errors'  => $errors,
    ]);
}

// ---------------------------------------------------------------------------
// Core email sender
//
// GUARANTEE: Only ever sends to the single client identified by $clientId.
//
// We do NOT use 'messagename' in the SendEmail call because WHMCS resolves
// recipients for product-type templates through its renewal automation engine,
// which can broadcast to ALL matching clients and ignore the 'id' param.
//
// Approach:
//   1. Fetch template content from tblemailtemplates (subject + body)
//   2. Ownership check — confirm the service belongs to this client
//   3. Replace merge fields with values for this specific client/service
//   4. Call SendEmail with customsubject + custommessage + id = $clientId
//      → WHMCS sends to exactly one recipient; no ambiguity possible
//   5. Write to WHMCS Activity Log (same log visible in Admin > Logs)
// ---------------------------------------------------------------------------
function _srn_send_notification($clientId, $serviceId, $templateName)
{
    // --- 1. Load email template ----------------------------------------
    $template = Capsule::table('tblemailtemplates')
        ->where('name', $templateName)
        ->first();

    if (!$template) {
        return [
            'success' => false,
            'message' => "Email template \"{$templateName}\" not found. "
                       . "Check Setup → Email Templates for the exact name.",
        ];
    }

    // --- 2. Load client -----------------------------------------------
    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$client) {
        return ['success' => false, 'message' => "Client #{$clientId} not found."];
    }

    // --- 3. Load service — OWNERSHIP CHECK (h.userid = $clientId) ------
    // This is the critical guard: we fetch the service only when it
    // belongs to the exact client we intend to email.  If a manipulated
    // request sends a mis-matched pair, the query returns null and we abort.
    $service = Capsule::table('tblhosting as h')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->select('h.*', 'p.name as product_name')
        ->where('h.id',     $serviceId)
        ->where('h.userid', $clientId)   // ← ownership check
        ->first();

    if (!$service) {
        // Log the suspicious mismatch to WHMCS activity log
        logActivity(
            "SRN SECURITY: Email blocked — Service #{$serviceId} does not belong to Client #{$clientId}. "
            . "Request rejected."
        );
        return [
            'success' => false,
            'message' => "Security check failed: Service #{$serviceId} does not belong to Client #{$clientId}.",
        ];
    }

    // --- 4. Build merge fields ----------------------------------------
    $fullName     = trim($client->firstname . ' ' . $client->lastname);
    $dueDate      = date('d/m/Y', strtotime($service->nextduedate));
    $amount       = number_format((float) $service->amount, 2);
    $billingCycle = ucfirst($service->billingcycle);
    $domain       = $service->domain;
    $productName  = $service->product_name;
    $status       = $service->domainstatus;

    $mergeFields = [
        // ---- Client -------------------------------------------------------
        '{$client_name}'               => $fullName,
        '{$client_firstname}'          => $client->firstname,
        '{$client_lastname}'           => $client->lastname,
        '{$client_email}'              => $client->email,
        '{$client_id}'                 => (string) $clientId,

        // ---- Service — all naming variants WHMCS templates use -----------
        '{$service_product_name}'      => $productName,   // ← fixes {$service_product_name}
        '{$service_product}'           => $productName,
        '{$service_domain}'            => $domain,
        '{$service_next_due_date}'     => $dueDate,
        '{$service_recurring_amount}'  => $amount,
        '{$service_amount}'            => $amount,
        '{$service_billing_cycle}'     => $billingCycle,
        '{$service_status}'            => $status,
        '{$service_id}'                => (string) $serviceId,

        // ---- Legacy / alternate names ------------------------------------
        '{$product_name}'              => $productName,
        '{$hosting_product}'           => $productName,
        '{$hosting_domain}'            => $domain,
        '{$next_due_date}'             => $dueDate,
        '{$recurring_amount}'          => $amount,
        '{$billing_cycle}'             => $billingCycle,

        // ---- Date helpers ------------------------------------------------
        '{$current_date}'              => date('d/m/Y'),
        '{$current_year}'              => date('Y'),
    ];

    // ---- Custom variables (defined by admin in the module UI) -----------
    $customVarsRaw = Capsule::table('tbladdonmodules')
        ->where('module', 'service_renewal_notifier')
        ->where('setting', 'custom_variables')
        ->value('value');

    if (!empty($customVarsRaw)) {
        foreach (explode("\n", $customVarsRaw) as $line) {
            $line = trim($line);
            // Skip blank lines and comment lines starting with #
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$varName, $varValue] = explode('=', $line, 2);
            $varName  = trim($varName);
            $varValue = trim($varValue);
            // Only accept {$...} style variable names
            if ($varName !== '' && preg_match('/^\{\$[\w]+\}$/', $varName)) {
                $mergeFields[$varName] = $varValue;
            }
        }
    }

    $subject = str_replace(array_keys($mergeFields), array_values($mergeFields), $template->subject);
    $body    = str_replace(array_keys($mergeFields), array_values($mergeFields), $template->message);

    // --- 5. Send to exactly ONE client via custom content mode ----------
    // Using customsubject + custommessage means WHMCS looks up ONLY the
    // client with id=$clientId and sends the content we provide.
    // The 'messagename' key is intentionally absent — using it for product
    // templates hands control back to WHMCS automation which can broadcast.
    $postData = [
        'id'            => (int) $clientId,   // ← hard-coded single recipient
        'customtype'    => 'general',
        'customsubject' => $subject,
        'custommessage' => $body,
    ];

    if (!empty($template->fromname)) {
        $postData['customfromname'] = $template->fromname;
    }
    if (!empty($template->fromemail)) {
        $postData['customfromemail'] = $template->fromemail;
    }

    // --- 5b. Suppress WHMCS SMTP debug logging for this send only -------
    // WHMCS stores the debug level in tblconfiguration. We zero it before
    // calling localAPI so no SMTP handshake lines are written to the
    // Activity Log, then restore whatever the admin had configured.
    $smtpDebugKey      = 'MailSMTPDebug';
    $smtpDebugRow      = Capsule::table('tblconfiguration')
        ->where('setting', $smtpDebugKey)->first();
    $originalDebugVal  = $smtpDebugRow ? $smtpDebugRow->value : null;

    if ($originalDebugVal !== null && (int) $originalDebugVal > 0) {
        try {
            Capsule::table('tblconfiguration')
                ->where('setting', $smtpDebugKey)
                ->update(['value' => '0']);
        } catch (\Exception $e) { /* non-fatal */ }
    }

    $results = localAPI('SendEmail', $postData);

    // Restore SMTP debug level immediately after the send
    if ($originalDebugVal !== null && (int) $originalDebugVal > 0) {
        try {
            Capsule::table('tblconfiguration')
                ->where('setting', $smtpDebugKey)
                ->update(['value' => $originalDebugVal]);
        } catch (\Exception $e) { /* non-fatal */ }
    }

    if ($results['result'] === 'success') {
        // --- 6. Write to WHMCS Activity Log ----------------------------
        // This creates entries identical to what you see in
        // Admin > Utilities > Logs > Activity Log.
        logActivity(
            "SRN: Renewal notification \"{$templateName}\" sent to "
            . "{$fullName} <{$client->email}> "
            . "(Client ID: {$clientId}, Service: {$domain}, Due: {$dueDate})",
            $clientId
        );

        return ['success' => true, 'message' => 'Email sent successfully.'];
    }

    return [
        'success' => false,
        'message' => isset($results['message']) ? $results['message'] : 'Unknown error from WHMCS SendEmail.',
    ];
}

// ---------------------------------------------------------------------------
// Helper: Save custom variables (AJAX handler)
// ---------------------------------------------------------------------------
function _srn_handle_save_custom_vars()
{
    header('Content-Type: application/json');

    $raw = isset($_POST['custom_vars']) ? $_POST['custom_vars'] : '';

    // Validate each non-blank line has format:  {$var_name} = some value
    $lines  = explode("\n", $raw);
    $clean  = [];
    $errors = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            $clean[] = $line;
            continue;
        }
        if (strpos($line, '=') === false) {
            $errors[] = "Invalid line (missing =): {$line}";
            continue;
        }
        [$varName, $varValue] = explode('=', $line, 2);
        $varName = trim($varName);
        if (!preg_match('/^\{\$[\w]+\}$/', $varName)) {
            $errors[] = "Invalid variable name \"{$varName}\" — must be like {$my_var}";
            continue;
        }
        $clean[] = $varName . ' = ' . trim($varValue);
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        return;
    }

    $value = implode("\n", $clean);

    try {
        $exists = Capsule::table('tbladdonmodules')
            ->where('module', 'service_renewal_notifier')
            ->where('setting', 'custom_variables')
            ->exists();

        if ($exists) {
            Capsule::table('tbladdonmodules')
                ->where('module', 'service_renewal_notifier')
                ->where('setting', 'custom_variables')
                ->update(['value' => $value]);
        } else {
            Capsule::table('tbladdonmodules')->insert([
                'module'  => 'service_renewal_notifier',
                'setting' => 'custom_variables',
                'value'   => $value,
            ]);
        }

        echo json_encode(['success' => true, 'message' => 'Custom variables saved.']);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ---------------------------------------------------------------------------
// Helper: Write to module's own send log
// ---------------------------------------------------------------------------
function _srn_log_sent($hostingId, $clientId, $clientName, $clientEmail, $domain, $templateName)
{
    try {
        $sentBy = isset($_SESSION['adminid']) ? 'admin#' . (int) $_SESSION['adminid'] : 'admin';

        Capsule::table('mod_srn_log')->insert([
            'hosting_id'    => (int) $hostingId,
            'client_id'     => (int) $clientId,
            'client_name'   => (string) $clientName,
            'client_email'  => (string) $clientEmail,
            'domain'        => (string) $domain,
            'template_name' => (string) $templateName,
            'sent_by'       => $sentBy,
            'sent_at'       => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        // Write the failure reason to WHMCS Activity Log so it is visible
        logActivity(
            'SRN: mod_srn_log insert failed — ' . $e->getMessage()
            . " (hosting_id={$hostingId}, client_id={$clientId})"
        );
    }
}
