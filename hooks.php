<?php

require_once __DIR__ . '/common_functions.php';
use WHMCS\Database\Capsule;

function update_gateway_fee1($vars)
{
    log_to_file("update_gateway_fee1 called with invoiceid: " . $vars['invoiceid']);
    $id = (int)$vars['invoiceid'];
    $invoice = Capsule::table('tblinvoices')->where('id', $id)->first();

    if ($invoice) {
        log_to_file("Invoice found: " . print_r($invoice, true));
        update_gateway_fee2(array(
            'paymentmethod' => $invoice->paymentmethod,
            'invoiceid' => $invoice->id
        ));
    }
}

function update_gateway_fee2($vars)
{
    log_to_file("update_gateway_fee2 called with invoiceid: " . $vars['invoiceid'] . " and paymentmethod: " . $vars['paymentmethod']);
    $invoiceId = (int)$vars['invoiceid'];
    $paymentMethod = $vars['paymentmethod'];

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        log_to_file("Invoice not found for id: " . $invoiceId);
        return;
    }

    $currency = Capsule::table('tblclients')->where('id', $invoice->userid)->value('currency');
    $currencyCode = Capsule::table('tblcurrencies')->where('id', $currency)->value('code');

    Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('notes', 'gateway_fees')
        ->delete();
    log_to_file("DB Delete: tblinvoiceitems where invoiceid=" . $invoiceId . " and notes='gateway_fees'");

    $params = Capsule::table('tbladdonmodules')
        ->whereIn('setting', [
            'fee_2_' . $paymentMethod . '_' . $currencyCode,
            'fee_1_' . $paymentMethod . '_' . $currencyCode
        ])
        ->pluck('value', 'setting')
        ->toArray();

    $fee1 = isset($params['fee_1_' . $paymentMethod . '_' . $currencyCode]) ? (float)$params['fee_1_' . $paymentMethod . '_' . $currencyCode] : 0;
    $fee2 = isset($params['fee_2_' . $paymentMethod . '_' . $currencyCode]) ? (float)$params['fee_2_' . $paymentMethod . '_' . $currencyCode] : 0;

    $totalFee = $fee1 + ($fee2 / 100) * $invoice->subtotal;

    // Consulta para obter o dado do setting 'name' para o método de pagamento
    $gatewayData = Capsule::table('tblpaymentgateways')
        ->where('gateway', $paymentMethod)
        ->where('setting', 'name') // Filtra pelo setting 'name'
        ->get(['gateway', 'setting', 'value']) // Retorna as colunas 'gateway', 'setting', e 'value'
        ->toArray(); // Converte para array	
    $textBeforeFee = Capsule::table('tbladdonmodules')
        ->where('module', 'gateway_fees')
        ->where('setting', 'text_before_fee_gateway_name')
        ->value('value');

    $textAfterFee = Capsule::table('tbladdonmodules')
        ->where('module', 'gateway_fees')
        ->where('setting', 'text_after_fee_gateway_name')
        ->value('value');
	
    Capsule::table('tblinvoiceitems')->insert([
        'invoiceid' => $invoiceId,
        'type' => 'Item',
        'description' => "$textBeforeFee " . $gatewayData[0]->value . " $textAfterFee ($$fee1 + $fee2%)",
        'amount' => $totalFee,
        'taxed' => 0,
        'notes' => 'gateway_fees'
    ]);
    log_to_file("DB Insert: tblinvoiceitems with totalFee=" . $totalFee);
}

function update_gateway_fee3($vars)
{
    log_to_file("update_gateway_fee3 called");
    // Exemple de traitement si nécessaire
}

add_hook("InvoiceChangeGateway", 1, "update_gateway_fee2");
add_hook("InvoiceCreated", 1, "update_gateway_fee1");
add_hook("AdminInvoicesControlsOutput", 2, "update_gateway_fee3");
add_hook("AdminInvoicesControlsOutput", 1, "update_gateway_fee1");
add_hook("InvoiceCreationAdminArea", 1, "update_gateway_fee1");
add_hook("InvoiceCreationAdminArea", 2, "update_gateway_fee3");
