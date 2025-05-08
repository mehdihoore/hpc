<?php
// reset_webhook.php

// !! Make sure this token is correct !!
$botToken = '7971076421:AAEp9HpGooR-JXnypvC73qZtG03BZ4biu-A';

// !! Make sure this is the EXACT correct HTTPS URL !!
$webhookUrl = 'https://alumglass.ir/telegram_webhook.php';

// Construct the API URL for setting the webhook
$apiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook";

echo "Attempting to set webhook to: " . htmlspecialchars($webhookUrl) . "\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, 1);
// Send the URL as POST data
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['url' => $webhookUrl]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Keep SSL verification enabled
curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Set a reasonable timeout

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "\nHTTP Status Code: " . $httpCode . "\n";
echo "cURL Error: " . ($error ? $error : 'None') . "\n";
echo "Telegram Response:\n<pre>" . htmlspecialchars($response) . "</pre>\n";

if (!$error && $httpCode == 200) {
    $responseData = json_decode($response, true);
    if ($responseData && $responseData['ok'] === true) {
        echo "\nSUCCESS: Webhook likely set correctly!\n";
    } else {
        echo "\nERROR: Telegram API returned an error.\n";
    }
} else {
    echo "\nERROR: Failed to communicate with Telegram API.\n";
}
?>