<?php
/**
 * ╔══════════════════════════════════════════════════════╗
 * ║      BOT TELEGRAM - SISTEMA DE GUERRAS              ║
 * ║         Versão Vercel + Upstash Redis               ║
 * ╚══════════════════════════════════════════════════════╝
 *
 * Variáveis de ambiente necessárias no Vercel:
 *   BOT_TOKEN      → Token do @BotFather
 *   UPSTASH_URL    → URL REST do Upstash Redis (https://...)
 *   UPSTASH_TOKEN  → Token do Upstash Redis
 */

// ════════════════════════════════════════════════════════
//  CONFIGURAÇÕES (via variáveis de ambiente do Vercel)
// ════════════════════════════════════════════════════════

define('BOT_TOKEN',     getenv('BOT_TOKEN')     ?: '8407063580:AAEQpwhl-CmxuzNSv-fgYzM7eVK7efMDUww');
define('API_URL',       'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('UPSTASH_URL',   getenv('UPSTASH_URL')   ?: 'https://boss-stinkbug-87986.upstash.io');
define('UPSTASH_TOKEN', getenv('UPSTASH_TOKEN') ?: 'gQAAAAAAAVeyAAIncDJhMTE2ODI4NThiNWU0OTUyODIyMzRmYjhiYTZmYjgzOXAyODc5ODY');
define('WAR_COOLDOWN',  20 * 60); // 20 minutos
define('DB_KEY',        'war_bot_data');

// ════════════════════════════════════════════════════════
//  MÁFIAS REGISTRADAS
// ════════════════════════════════════════════════════════

$MAFIAS = [
    'os_cara_de_verdade' => [
        'nome'  => '🃏 Os Cara de Verdade',
        'lider' => 'Matheus Bar',
        'loja'  => false,
    ],
    'peaky_blinders' => [
        'nome'  => '🎩 Peaky Blinders',
        'lider' => 'Gui_Vilao',
        'loja'  => true,  // ⭐ Tem loja - MAIS CHANCE NO SORTEIO
    ],
    'tambov_criminal' => [
        'nome'  => '🦅 Tambov Criminal',
        'lider' => 'Mr_Capone',
        'loja'  => true,  // ⭐ Tem loja - MAIS CHANCE NO SORTEIO
    ],
    'ismael_criminal' => [
        'nome'  => '🐍 Ismael Criminal',
        'lider' => 'Maya Dias',
        'loja'  => false,
    ],
];

// ════════════════════════════════════════════════════════
//  BANCO DE DADOS — Upstash Redis (HTTP REST)
//  Vercel é serverless: não pode gravar arquivos em disco.
//  Upstash é gratuito e funciona via HTTP, perfeito aqui.
// ════════════════════════════════════════════════════════

function dbGet(): array {
    if (!UPSTASH_URL || !UPSTASH_TOKEN) {
        return ['wars' => [], 'results' => []];
    }
    $ch = curl_init(UPSTASH_URL . '/get/' . DB_KEY);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . UPSTASH_TOKEN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (empty($data['result'])) {
        return ['wars' => [], 'results' => []];
    }
    return json_decode($data['result'], true) ?? ['wars' => [], 'results' => []];
}

function dbSet(array $data): void {
    if (!UPSTASH_URL || !UPSTASH_TOKEN) return;
    $value = json_encode($data, JSON_UNESCAPED_UNICODE);
    $ch    = curl_init(UPSTASH_URL . '/set/' . DB_KEY);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $value,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . UPSTASH_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ════════════════════════════════════════════════════════
//  FUNÇÕES DE API TELEGRAM
// ════════════════════════════════════════════════════════

function apiRequest(string $method, array $params = []): ?array {
    $ch = curl_init(API_URL . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

function sendMessage(int|string $chatId, string $text): void {
    apiRequest('sendMessage', [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);
}

// ════════════════════════════════════════════════════════
//  LÓGICA DE SORTEIO
//  Máfias com LOJA têm PESO DUPLO
// ════════════════════════════════════════════════════════

function sortearMafias(): array {
    global $MAFIAS;

    $pool = [];
    foreach ($MAFIAS as $id => $m) {
        $pool[] = $id;
        if ($m['loja']) $pool[] = $id; // bônus loja
    }

    shuffle($pool);
    $atacante = $pool[array_rand($pool)];

    do {
        shuffle($pool);
        $defensor = $pool[array_rand($pool)];
    } while ($defensor === $atacante);

    return [$atacante, $defensor];
}

// ════════════════════════════════════════════════════════
//  COMANDOS
// ════════════════════════════════════════════════════════

function cmdGstart(int $chatId): void {
    global $MAFIAS;

    $db      = dbGet();
    $agora   = time();
    $lastWar = $db['wars']['last_war_time'] ?? 0;
    $elapsed = $agora - $lastWar;

    // ── Cooldown
    if ($elapsed < WAR_COOLDOWN) {
        $restante = WAR_COOLDOWN - $elapsed;
        $min      = floor($restante / 60);
        $seg      = $restante % 60;
        sendMessage($chatId,
            "⏳ <b>AGUARDE!</b>\n\n" .
            "Uma guerra já aconteceu recentemente.\n" .
            "Próxima disponível em: <b>{$min}m {$seg}s</b>\n\n" .
            "⚠️ Restrição de <b>20 minutos</b> entre guerras."
        );
        return;
    }

    // ── Sortear
    [$atkId, $defId] = sortearMafias();
    $atk = $MAFIAS[$atkId];
    $def = $MAFIAS[$defId];

    // ── Simular batalha
    $chanceAtk = 50 + ($atk['loja'] ? 10 : 0) - ($def['loja'] ? 10 : 0);
    $chanceAtk = max(20, min(80, $chanceAtk));
    $atkVenceu = rand(1, 100) <= $chanceAtk;

    $vencedor = $atkVenceu ? $atk  : $def;
    $perdedor = $atkVenceu ? $def  : $atk;
    $vencId   = $atkVenceu ? $atkId : $defId;
    $perdId   = $atkVenceu ? $defId : $atkId;

    // ── Salvar no DB
    $db['wars']['last_war_time'] = $agora;
    foreach ([$atkId, $defId] as $mid) {
        if (!isset($db['results'][$mid])) {
            $db['results'][$mid] = ['vitorias' => 0, 'derrotas' => 0];
        }
    }
    $db['results'][$vencId]['vitorias']++;
    $db['results'][$perdId]['derrotas']++;
    $db['wars']['historico'][] = [
        'data'     => date('d/m/Y H:i'),
        'atacante' => $atkId,
        'defensor' => $defId,
        'vencedor' => $vencId,
    ];
    dbSet($db);

    // ── Mensagem
    $la = $atk['loja'] ? ' 🏪' : '';
    $ld = $def['loja'] ? ' 🏪' : '';
    $re = $atkVenceu ? '🏆' : '🛡️';

    $msg  = "╔══════════════════════════╗\n";
    $msg .= "║   ⚔️  <b>GUERRA INICIADA!</b>  ⚔️   ║\n";
    $msg .= "╚══════════════════════════╝\n\n";
    $msg .= "🗡️ <b>ATACANTE</b>\n";
    $msg .= "  {$atk['nome']}{$la}\n";
    $msg .= "  👑 Líder: <b>{$atk['lider']}</b>\n\n";
    $msg .= "🛡️ <b>DEFENSOR</b>\n";
    $msg .= "  {$def['nome']}{$ld}\n";
    $msg .= "  👑 Líder: <b>{$def['lider']}</b>\n\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "💥 <i>A batalha foi travada...</i>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $msg .= "{$re} <b>VENCEDOR:</b> {$vencedor['nome']}\n";
    $msg .= "   👑 <b>{$vencedor['lider']}</b>\n\n";
    $msg .= "💀 <b>DERROTADO:</b> {$perdedor['nome']}\n";
    $msg .= "   💀 <b>{$perdedor['lider']}</b>\n\n";
    $msg .= "⏱️ Próxima guerra em <b>20 minutos</b>\n";
    $msg .= "🏪 = Organização com loja (bônus no sorteio)";

    sendMessage($chatId, $msg);
}

function cmdRanking(int $chatId): void {
    global $MAFIAS;

    $db      = dbGet();
    $results = $db['results'] ?? [];
    $ranking = [];

    foreach ($MAFIAS as $id => $m) {
        $ranking[] = [
            'mafia' => $m,
            'v'     => $results[$id]['vitorias'] ?? 0,
            'd'     => $results[$id]['derrotas']  ?? 0,
        ];
    }
    usort($ranking, fn($a, $b) => $b['v'] <=> $a['v']);

    $msg     = "🏆 <b>RANKING DAS MÁFIAS</b> 🏆\n";
    $msg    .= "━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $medals  = ['🥇', '🥈', '🥉', '4️⃣'];

    foreach ($ranking as $i => $r) {
        $loja = $r['mafia']['loja'] ? ' 🏪' : '';
        $msg .= "{$medals[$i]} <b>{$r['mafia']['nome']}</b>{$loja}\n";
        $msg .= "   👑 {$r['mafia']['lider']}\n";
        $msg .= "   ✅ Vitórias: <b>{$r['v']}</b>  ❌ Derrotas: <b>{$r['d']}</b>\n\n";
    }
    $msg .= "🏪 = Tem loja (bônus no sorteio)";
    sendMessage($chatId, $msg);
}

function cmdMafias(int $chatId): void {
    global $MAFIAS;

    $msg  = "🔱 <b>ORGANIZAÇÕES REGISTRADAS</b> 🔱\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    foreach ($MAFIAS as $m) {
        $loja = $m['loja'] ? "\n   🏪 <i>Possui loja (bônus no sorteio)</i>" : '';
        $msg .= "{$m['nome']}\n";
        $msg .= "   👑 Líder: <b>{$m['lider']}</b>{$loja}\n\n";
    }

    $msg .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "Use /gstart para iniciar uma guerra!\n";
    $msg .= "⏱️ Cooldown entre guerras: <b>20 minutos</b>";
    sendMessage($chatId, $msg);
}

function cmdAjuda(int $chatId): void {
    $msg  = "📖 <b>COMANDOS DO BOT DE GUERRAS</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $msg .= "/gstart  — ⚔️ Iniciar guerra (sorteio automático)\n";
    $msg .= "/mafias  — 🔱 Ver todas as organizações\n";
    $msg .= "/ranking — 🏆 Ver placar de vitórias\n";
    $msg .= "/ajuda   — 📖 Este menu\n\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "🎲 O sorteio escolhe automaticamente quem ataca\n";
    $msg .= "e quem defende. <b>Máfias com loja</b> têm bônus!\n\n";
    $msg .= "⏱️ Restrição: <b>20 minutos</b> entre guerras.";
    sendMessage($chatId, $msg);
}

// ════════════════════════════════════════════════════════
//  ENTRY POINT — Webhook
// ════════════════════════════════════════════════════════

$input  = file_get_contents('php://input');
$update = json_decode($input, true);

if ($update) {
    $message = $update['message'] ?? $update['channel_post'] ?? null;

    if ($message) {
        $chatId = $message['chat']['id'];
        $text   = trim($message['text'] ?? '');
        $text   = preg_replace('/@\w+/', '', $text); // remove @bot

        if (str_starts_with($text, '/gstart'))  cmdGstart($chatId);
        elseif (str_starts_with($text, '/ranking')) cmdRanking($chatId);
        elseif (str_starts_with($text, '/mafias'))  cmdMafias($chatId);
        elseif (str_starts_with($text, '/ajuda'))   cmdAjuda($chatId);
        elseif (str_starts_with($text, '/start'))   cmdAjuda($chatId);
        elseif (str_starts_with($text, '/help'))    cmdAjuda($chatId);
    }
}

http_response_code(200);
echo 'ok';
