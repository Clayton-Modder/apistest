<?php
/**
 * Acesse: https://seu-projeto.vercel.app/setup
 * para registrar o webhook automaticamente.
 */

$token      = getenv('BOT_TOKEN') ?: '';
$webhookUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/webhook';

if (!$token) {
    die('<h2>❌ BOT_TOKEN não configurado nas variáveis de ambiente do Vercel.</h2>');
}

$apiUrl = "https://api.telegram.org/bot{$token}/setWebhook";
$ch     = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['url' => $webhookUrl]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
]);
$result = curl_exec($ch);
curl_close($ch);
$data = json_decode($result, true);

header('Content-Type: text/html; charset=utf-8');
echo '<meta charset="utf-8">';
echo '<h2>🤖 Configuração do Webhook</h2>';
echo '<p><b>URL registrada:</b> ' . htmlspecialchars($webhookUrl) . '</p>';
echo '<p><b>Resposta do Telegram:</b></p>';
echo '<pre>' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
if ($data['ok'] ?? false) {
    echo '<h3>✅ Webhook registrado com sucesso! O bot está ativo.</h3>';
} else {
    echo '<h3>❌ Erro ao registrar webhook. Verifique o token.</h3>';
}
