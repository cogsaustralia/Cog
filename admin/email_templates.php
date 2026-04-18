<?php
// email_templates.php is a legacy entry point.
// The email templates editor has moved to messages.php?section=email_templates,
// which includes CSRF protection and a consistent admin UI.
require_once __DIR__ . '/includes/ops_workflow.php';
ops_require_admin();
header('Location: ./messages.php?section=email_templates&legacy_entry=1');
exit;
