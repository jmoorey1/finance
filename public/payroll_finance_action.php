<?php

require_once '../config/db.php';
require_once '../scripts/payroll_finance.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    header(
        'Content-Type: text/plain; charset=utf-8'
    );

    echo "Method not allowed.";

    exit;
}

$action =
    (string)(
        $_POST['action']
        ?? ''
    );

$payslipId =
    (int)(
        $_POST['payslip_id']
        ?? 0
    );

$returnUrl =
    $payslipId > 0
        ? (
            'payroll_payslip.php?id='
            . $payslipId
        )
        : 'payroll.php';

try {
    if ($action === 'link') {
        $transactionId =
            (int)(
                $_POST['transaction_id']
                ?? 0
            );

        payroll_finance_link_transaction(
            $pdo,
            $payslipId,
            $transactionId
        );

        header(
            'Location: '
            . $returnUrl
            . '&finance_saved=linked'
        );

        exit;
    }

    if ($action === 'unlink') {
        $linkId =
            (int)(
                $_POST['link_id']
                ?? 0
            );

        payroll_finance_unlink_transaction(
            $pdo,
            $payslipId,
            $linkId
        );

        header(
            'Location: '
            . $returnUrl
            . '&finance_saved=unlinked'
        );

        exit;
    }

    throw new RuntimeException(
        'Invalid Payroll Finance action.'
    );

} catch (Throwable $e) {
    header(
        'Location: '
        . $returnUrl
        . '&finance_error='
        . rawurlencode(
            $e->getMessage()
        )
    );

    exit;
}
