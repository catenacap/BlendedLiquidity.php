<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$apiKey = 'xxxx';
$basePath = __DIR__;

// === FETCH FRED === (monthly average)
function fetchFREDSeries($id) {
    $url = "https://fred.stlouisfed.org/graph/fredgraph.csv?id=$id&cosd=2015-01-01&coed=" . date("Y-m-d");
    $data = @file_get_contents($url);
    if (!$data) return [];
    $lines = explode("\n", trim($data)); array_shift($lines);
    $monthly = [];

    foreach ($lines as $line) {
        [$d, $v] = str_getcsv($line);
        if ($v !== '.' && is_numeric($v)) {
            $month = date('Y-m', strtotime($d));
            $monthly[$month][] = floatval($v);
        }
    }

    $out = [];
    foreach ($monthly as $month => $vals) {
        $lastDay = date('Y-m-t', strtotime($month));
        $out[$lastDay] = array_sum($vals) / count($vals);
    }

    return $out;
}

// === FETCH YAHOO === (monthly)
function fetchYahooHistory($symbol, $apiKey) {
    $url = "https://yahoo-finance15.p.rapidapi.com/api/v1/markets/stock/history?symbol=$symbol&interval=1mo";
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: yahoo-finance15.p.rapidapi.com",
            "x-rapidapi-key: $apiKey"
        ]
    ]);
    $res = curl_exec($curl); curl_close($curl);
    $json = json_decode($res, true);
    $out = [];
    if (!isset($json['body'])) return [];
    foreach ($json['body'] as $entry) {
        if (!empty($entry['date']) && is_numeric($entry['close'])) {
            $out[$entry['date']] = floatval($entry['close']);
        }
    }
    return $out;
}

// === HELPERS ===
function getNearestPast($series, $targetDate) {
    $dates = array_keys($series); rsort($dates);
    foreach ($dates as $d) if ($d <= $targetDate && isset($series[$d])) return $series[$d];
    return null;
}
function forwardFill($series) {
    $filled = []; $lastVal = null;
    foreach ($series as $date => $val) {
        if ($val !== null) $lastVal = $val;
        $filled[$date] = $lastVal;
    }
    return $filled;
}
function zScore($series) {
    $values = array_values($series);
    $mean = array_sum($values) / count($values);
    $std = sqrt(array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values));
    $zs = [];
    foreach (array_keys($series) as $date) {
        $val = $series[$date];
        $zs[$date] = $std ? ($val - $mean) / $std : 0;
    }
    return $zs;
}
function rollingAvg($series, $n) {
    $out = []; $keys = array_keys($series);
    for ($i = $n - 1; $i < count($keys); $i++) {
        $slice = array_slice($series, $i - $n + 1, $n);
        $avg = array_sum(array_values($slice)) / count($slice);
        $out[$keys[$i]] = $avg;
    }
    return $out;
}
function rateOfChange($series, $lag) {
    $out = []; $keys = array_keys($series);
    for ($i = $lag; $i < count($keys); $i++) {
        $cur = $series[$keys[$i]];
        $prev = $series[$keys[$i - $lag]];
        $out[$keys[$i]] = $prev ? ($cur - $prev) / abs($prev) : 0;
    }
    return $out;
}
function saveCSV($filename, $series) {
    $fp = fopen($filename, 'w');
    fputcsv($fp, ['Date', 'Value']);
    foreach ($series as $d => $v) fputcsv($fp, [$d, round($v, 4)]);
    fclose($fp);
}
function echoLast20($label, $series) {
    echo "\n\n==== $label (20 most recent since 2022) ====\n";
    $filtered = array_filter($series, fn($d) => $d >= '2022-01-01', ARRAY_FILTER_USE_KEY);
    $last20 = array_slice($filtered, -20, 20, true);
    foreach ($last20 as $d => $v) {
        echo "$d => " . round($v, 4) . "\n";
    }
}

// === FETCH DATA ===
$walcl  = fetchFREDSeries("WALCL");
$m0     = fetchFREDSeries("MYAGM0CNM189N");
$cnyusd = fetchYahooHistory("CNYUSD%3DX", $apiKey);
$move   = fetchYahooHistory("%5EMOVE", $apiKey);

// === Forward-fill each series
$walcl_ff  = forwardFill($walcl);
$m0_ff     = forwardFill($m0);
$cnyusd_ff = forwardFill($cnyusd);
$move_ff   = forwardFill($move);

// === Align and fallback for missing months
$dates = array_keys($walcl_ff);
sort($dates);
$aligned = [];

foreach ($dates as $date) {
    $walcl_val = $walcl_ff[$date] * 1e6;
    $m0_val = getNearestPast($m0_ff, $date);
    $cny_val = getNearestPast($cnyusd_ff, $date);
    $mv = getNearestPast($move_ff, $date);
    if ($m0_val === null || $cny_val === null || $mv === null) continue;
    $aligned[$date] = [
        'walcl' => $walcl_val,
        'm0_usd' => $m0_val * 1e6 * $cny_val,
        'move' => $mv
    ];
}

// === Z-Score
$walcl_z = zScore(array_map(fn($r) => $r['walcl'], $aligned));
$m0_z    = zScore(array_map(fn($r) => $r['m0_usd'], $aligned));
$move_z  = zScore(array_map(fn($r) => $r['move'], $aligned));

// === Build Blended Components
$fast = $blended = [];
foreach ($walcl_z as $d => $z1) {
    if (!isset($m0_z[$d], $move_z[$d])) continue;
    $fast[$d] = -0.45 * $z1 + -0.24 * $m0_z[$d];
    $blended[$d] = $fast[$d] + 0.10 * $move_z[$d];
}
$slow = rollingAvg($fast, 12);
$roc = rateOfChange($blended, 12);

// === Save CSVs
saveCSV("$basePath/Blended_Liquidity.csv", $blended);
saveCSV("$basePath/Blended_Liquidity_Fast.csv", $fast);
saveCSV("$basePath/Blended_Liquidity_Slow.csv", $slow);
saveCSV("$basePath/Blended_Liquidity_RoC.csv", $roc);

// === Debug Echo
echoLast20("ψ (Blended)", $blended);
echoLast20("Fast", $fast);
echoLast20("Slow", $slow);
echoLast20("12m RoC", $roc);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Blended Liquidity (ψ)</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style> body { background:#111; color:#eee; font-family:sans-serif; } #chart { height:95vh; } </style>
</head>
<body>
<h2>📉 Blended Liquidity Index (ψ) + Components</h2>
<div id="chart"></div>
<?php
function readCSV($file) {
    $rows = array_map('str_getcsv', file($file));
    array_shift($rows);
    $dates = []; $vals = [];
    foreach ($rows as $r) {
        $dates[] = $r[0]; $vals[] = floatval($r[1]);
    }
    return [$dates, $vals];
}
[$d1, $v1] = readCSV("$basePath/Blended_Liquidity.csv");
[$d2, $v2] = readCSV("$basePath/Blended_Liquidity_Fast.csv");
[$d3, $v3] = readCSV("$basePath/Blended_Liquidity_Slow.csv");
[$d4, $v4] = readCSV("$basePath/Blended_Liquidity_RoC.csv");
?>
<script>
Plotly.newPlot('chart', [
  { x: <?= json_encode($d1) ?>, y: <?= json_encode($v1) ?>, name: 'ψ', yaxis: 'y', line:{color:'#00ccff'} },
  { x: <?= json_encode($d2) ?>, y: <?= json_encode($v2) ?>, name: 'Fast', yaxis: 'y2', line:{color:'#33ff33',dash:'dot'} },
  { x: <?= json_encode($d3) ?>, y: <?= json_encode($v3) ?>, name: 'Slow', yaxis: 'y3', line:{color:'#ffaa00',dash:'dash'} },
  { x: <?= json_encode($d4) ?>, y: <?= json_encode($v4) ?>, name: '12m RoC', yaxis: 'y4', line:{color:'#ff3333'} }
], {
  plot_bgcolor: '#111', paper_bgcolor: '#111', font:{color:'#eee'},
  xaxis:{title:'Date'},
  yaxis:{title:'ψ', side:'left'},
  yaxis2:{title:'Fast', overlaying:'y', side:'right'},
  yaxis3:{title:'Slow', overlaying:'y', side:'right', position:0.95},
  yaxis4:{title:'12m RoC', overlaying:'y', side:'right', position:0.9},
  legend:{orientation:'h',y:-0.3}
});
</script>
</body>
</html>
