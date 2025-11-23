<?php
// ==================== ERROR DISPLAY ====================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$apiKey   = 'xxxx';
$basePath = __DIR__;

// PLOT CUTOFF
$PLOT_FROM = '2010-07-01';

// =======================================================
// MAIN DB (catenacap_macromicro)
// =======================================================
$mysqli = new mysqli(
    "xxx",
    "xxx",
    "xxx",
    "xxxx"
);
if ($mysqli->connect_errno) {
    die("DB connection failed (main): " . $mysqli->connect_error);
}

// =======================================================
// SECOND DB FOR MBCN (catenacap_econdb)
// =======================================================
$mysqli2 = new mysqli(
    "xxx",
    "xxxx",
    "xxxx",
    "xxx"
);
if ($mysqli2->connect_errno) {
    die("DB connection failed (econdb): " . $mysqli2->connect_error);
}

// =======================================================
// FETCH MOVE FROM DB (DAILY → MONTH-END NEAREST PAST)
// =======================================================
function fetchMoveFromDB($mysqli) {
    $sql = "SELECT `date`, `value` FROM `move_index_usd_daily` ORDER BY `date` ASC";
    $res = $mysqli->query($sql);
    if (!$res) die("MOVE query failed: " . $mysqli->error);

    $daily = [];
    while ($row = $res->fetch_assoc()) {
        if ($row['value'] !== null && is_numeric($row['value'])) {
            $daily[$row['date']] = (float)$row['value'];
        }
    }
    if (empty($daily)) return [];

    $start   = array_key_first($daily);
    $end     = array_key_last($daily);
    $current = strtotime($start);

    $monthly = [];

    while ($current <= strtotime($end)) {
        $monthEnd = date('Y-m-t', $current);
        $nearest  = null;

        foreach ($daily as $d => $v) {
            if ($d <= $monthEnd) {
                $nearest = $v;
            } else {
                break;
            }
        }

        if ($nearest !== null) {
            $monthly[$monthEnd] = $nearest;
        }

        $current = strtotime("+1 month", $current);
    }
    return $monthly;
}

// =======================================================
// FRED (WALCL, MONTH-END AVG)
// =======================================================
function fetchFREDSeries($id) {
    $url  = "https://fred.stlouisfed.org/graph/fredgraph.csv?id=$id&cosd=2007-01-01&coed=" . date("Y-m-d");
    $data = @file_get_contents($url);
    if (!$data) return [];

    $lines = explode("\n", trim($data));
    array_shift($lines);

    $group = [];
    foreach ($lines as $ln) {
        if ($ln === '') continue;
        [$d, $v] = str_getcsv($ln);
        if ($v !== "." && is_numeric($v)) {
            $month = date('Y-m', strtotime($d));
            $group[$month][] = (float)$v;
        }
    }

    $out = [];
    foreach ($group as $m => $vals) {
        $last       = date('Y-m-t', strtotime($m));
        $out[$last] = array_sum($vals) / count($vals);
    }

    return $out;
}

// =======================================================
// RAPID API (CNYUSD 1mo, >= 2017-01-01)
// =======================================================
function fetchYahooCNYUSD($apiKey){
    $url = "https://yahoo-finance15.p.rapidapi.com/api/v1/markets/stock/history?symbol=CNYUSD%3DX&interval=1mo";

    $curl = curl_init();
    curl_setopt_array($curl,[
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "x-rapidapi-host: yahoo-finance15.p.rapidapi.com",
            "x-rapidapi-key: $apiKey"
        ]
    ]);
    $res = curl_exec($curl);
    curl_close($curl);

    $json = json_decode($res, true);
    if (!isset($json['body']) || !is_array($json['body'])) return [];

    $o = [];
    foreach ($json['body'] as $e) {
        if (empty($e['date']) || !is_numeric($e['close'])) continue;
        $d = substr($e['date'], 0, 10);
        if ($d < '2017-01-01') continue;
        $o[$d] = (float)$e['close'];
    }
    ksort($o);
    return $o;
}

// =======================================================
// HELPERS
// =======================================================
function getNearestPast($series, $target){
    if (empty($series)) return null;
    $keys = array_keys($series);
    rsort($keys);
    foreach ($keys as $d) {
        if ($d <= $target) return $series[$d];
    }
    return null;
}

function forwardFill($s){
    $o = [];
    $last = null;
    foreach ($s as $d => $v) {
        if ($v !== null) $last = $v;
        $o[$d] = $last;
    }
    return $o;
}

function zScore($s){
    $vals = array_values($s);
    $n    = count($vals);
    if ($n === 0) return [];

    $mean = array_sum($vals) / $n;
    $std  = sqrt(array_sum(array_map(fn($v)=>($v-$mean)**2, $vals)) / $n);

    $out = [];
    foreach ($s as $d => $v) {
        $out[$d] = $std ? ($v - $mean) / $std : 0;
    }
    return $out;
}

function rollingAvg($s, $n){
    $o = [];
    $k = array_keys($s);
    $nkeys = count($k);

    for ($i = $n - 1; $i < $nkeys; $i++) {
        $slice = array_slice($s, $i - $n + 1, $n, true);
        $o[$k[$i]] = array_sum($slice) / count($slice);
    }
    return $o;
}

function rateOfChange($s, $lag){
    $o = [];
    $k = array_keys($s);
    $n = count($k);
    for ($i = $lag; $i < $n; $i++) {
        $cur  = $s[$k[$i]];
        $prev = $s[$k[$i - $lag]];
        $o[$k[$i]] = $prev ? ($cur - $prev) / abs($prev) : 0;
    }
    return $o;
}

function padDate($date, $offset){
    return date('Y-m-d', strtotime("$date $offset"));
}

// =======================================================
// FETCH DATA
// =======================================================

// WALCL
$walcl = fetchFREDSeries("WALCL");

// MBCN
$sqlMBCN = "SELECT `date`, `value` FROM `MBCN` ORDER BY `date` ASC";
$resMBCN = $mysqli2->query($sqlMBCN);
$m0 = [];
while ($row = $resMBCN->fetch_assoc()) {
    $m0[$row['date']] = (float)$row['value'];
}

// CNYUSD DB (2007 → 2017)
$sqlCNY = "
    SELECT `date`, `value`
    FROM `CNYUSD`
    WHERE `date` >= '2007-06-01' AND `date` < '2017-01-01'
    ORDER BY `date` ASC
";
$resCNY = $mysqli->query($sqlCNY);
$cny_db = [];
while ($row = $resCNY->fetch_assoc()) {
    $cny_db[$row['date']] = (float)$row['value'];
}

// CNYUSD API (2017+)
$cny_api = fetchYahooCNYUSD($apiKey);

// merge
$cnyusd = array_merge($cny_db, $cny_api);
ksort($cnyusd);

// MOVE
$move = fetchMoveFromDB($mysqli);

// BTC
$sqlBTC = "SELECT `date`,`value` FROM `Bitcoin-USD` ORDER BY `date` ASC";
$rBTC   = $mysqli->query($sqlBTC);
$btc = [];
while ($row = $rBTC->fetch_assoc()) {
    $btc[$row['date']] = (float)$row['value'];
}

// =======================================================
// FORWARD-FILL
// =======================================================
$walcl_ff  = forwardFill($walcl);
$m0_ff     = forwardFill($m0);
$cnyusd_ff = forwardFill($cnyusd);
$move_ff   = forwardFill($move);

// =======================================================
// ALIGN SERIES
// =======================================================
$dates = array_keys($walcl_ff);
sort($dates);

$aligned = [];

foreach ($dates as $date){
    $walcl_val = $walcl_ff[$date] * 1e6;

    $m0_val  = getNearestPast($m0_ff, $date);
    $cny_val = getNearestPast($cnyusd_ff, $date);
    $mv      = getNearestPast($move_ff, $date);

    if ($m0_val === null || $cny_val === null || $mv === null) continue;

    $aligned[$date] = [
        'walcl'  => $walcl_val,
        'm0_usd' => $m0_val * 1e6 * $cny_val,
        'move'   => $mv
    ];
}

// =======================================================
// Z-SCORES
// =======================================================
$walcl_z = zScore(array_map(fn($r)=>$r['walcl'],  $aligned));
$m0_z    = zScore(array_map(fn($r)=>$r['m0_usd'], $aligned));
$move_z  = zScore(array_map(fn($r)=>$r['move'],   $aligned));

// =======================================================
// BUILD BLENDED SERIES
// =======================================================
$fast    = [];
$blended = [];

foreach ($walcl_z as $d => $z1){
    if (!isset($m0_z[$d], $move_z[$d])) continue;

    $fast[$d]    = -0.45 * $z1 + -0.24 * $m0_z[$d];
    $blended[$d] = $fast[$d] + 0.10 * $move_z[$d];
}

$slow = rollingAvg($fast, 12);
$roc  = rateOfChange($blended, 12);

// =======================================================
// TURNING POINT DETECTION
// =======================================================
function findPeaks($s){
    $k = array_keys($s);
    $v = array_values($s);
    $p = [];
    for ($i = 1; $i < count($v) - 1; $i++) {
        if ($v[$i] > $v[$i-1] && $v[$i] > $v[$i+1]) {
            $p[$k[$i]] = $v[$i];
        }
    }
    return $p;
}

function findTroughs($s){
    $k = array_keys($s);
    $v = array_values($s);
    $t = [];
    for ($i = 1; $i < count($v) - 1; $i++) {
        if ($v[$i] < $v[$i-1] && $v[$i] < $v[$i+1]) {
            $t[$k[$i]] = $v[$i];
        }
    }
    return $t;
}

$btc_peak   = findPeaks($btc);
$btc_trough = findTroughs($btc);
$psi_peak   = findPeaks($blended);
$psi_trough = findTroughs($blended);

$red_dates   = array_intersect(array_keys($btc_peak),   array_keys($psi_peak));
$green_dates = array_intersect(array_keys($btc_trough), array_keys($psi_trough));

$shapes = [];

foreach ($red_dates as $d){
    $shapes[] = [
        "type"      => "rect",
        "xref"      => "x",
        "yref"      => "paper",
        "x0"        => padDate($d, "-5 days"),
        "x1"        => padDate($d, "+5 days"),
        "y0"        => 0,
        "y1"        => 1,
        "line"      => ["width" => 0],
        "fillcolor" => "rgba(255,0,0,0.25)"
    ];
}

foreach ($green_dates as $d){
    $shapes[] = [
        "type"      => "rect",
        "xref"      => "x",
        "yref"      => "paper",
        "x0"        => padDate($d, "-5 days"),
        "x1"        => padDate($d, "+5 days"),
        "y0"        => 0,
        "y1"        => 1,
        "line"      => ["width" => 0],
        "fillcolor" => "rgba(0,255,0,0.20)"
    ];
}

$shapes_json = json_encode($shapes);

// =======================================================
// CSV OUTPUT
// =======================================================
function saveCSV($f,$s){
    $fp = fopen($f,'w');
    fputcsv($fp,['Date','Value']);
    foreach ($s as $d=>$v) {
        fputcsv($fp,[$d, round($v,4)]);
    }
    fclose($fp);
}

saveCSV("$basePath/Blended_Liquidity.csv"      , $blended);
saveCSV("$basePath/Blended_Liquidity_Fast.csv" , $fast);
saveCSV("$basePath/Blended_Liquidity_Slow.csv" , $slow);
saveCSV("$basePath/Blended_Liquidity_RoC.csv"  , $roc);

// =======================================================
// HTML OUTPUT
// =======================================================
?>
<!DOCTYPE html>
<html>
<head>
    <title>Blended Liquidity (ψ)</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        body { background:#111; color:#eee; font-family:sans-serif; }
        #chart { height:95vh; }
    </style>
</head>
<body>

<h2>📉 Blended Liquidity Index (ψ) + Components</h2>
<div id="chart"></div>

<?php
function readCSV($f){
    $r = array_map('str_getcsv', file($f));
    array_shift($r);
    $d = []; $v = [];
    foreach ($r as $x){
        $d[] = $x[0];
        $v[] = (float)$x[1];
    }
    return [$d,$v];
}

[$d1,$v1] = readCSV("$basePath/Blended_Liquidity.csv");
[$d2,$v2] = readCSV("$basePath/Blended_Liquidity_Fast.csv");
[$d3,$v3] = readCSV("$basePath/Blended_Liquidity_Slow.csv");
[$d4,$v4] = readCSV("$basePath/Blended_Liquidity_RoC.csv");

$btc_dates = array_keys($btc);
$btc_vals  = array_values($btc);

// ===== CUT SERIES TO JULY 2010 =====
function cutSeries($dates, $vals, $cut){
    $newD = [];
    $newV = [];
    for($i=0;$i<count($dates);$i++){
        if ($dates[$i] >= $cut){
            $newD[] = $dates[$i];
            $newV[] = $vals[$i];
        }
    }
    return [$newD, $newV];
}

[$d1,$v1] = cutSeries($d1,$v1,$PLOT_FROM);
[$d2,$v2] = cutSeries($d2,$v2,$PLOT_FROM);
[$d3,$v3] = cutSeries($d3,$v3,$PLOT_FROM);
[$d4,$v4] = cutSeries($d4,$v4,$PLOT_FROM);
[$btc_dates,$btc_vals] = cutSeries($btc_dates,$btc_vals,$PLOT_FROM);
?>

<script>
Plotly.newPlot('chart',[

    {x:<?=json_encode($d1)?>, y:<?=json_encode($v1)?>,
     name:'ψ', yaxis:'y', line:{color:'#00ccff'}},

    {x:<?=json_encode($d2)?>, y:<?=json_encode($v2)?>,
     name:'Fast', yaxis:'y2', line:{color:'#33ff33', dash:'dot'}},

    {x:<?=json_encode($d3)?>, y:<?=json_encode($v3)?>,
     name:'Slow', yaxis:'y3', line:{color:'#ffaa00', dash:'dash'}},

    {x:<?=json_encode($d4)?>, y:<?=json_encode($v4)?>,
     name:'12m RoC', yaxis:'y4', visible:'legendonly', line:{color:'#ff3333'}},

    {x:<?=json_encode($btc_dates)?>, y:<?=json_encode($btc_vals)?>,
     name:'BTC (log)', yaxis:'y5', line:{color:'#ffffff'}}

],{
    plot_bgcolor:'#111',
    paper_bgcolor:'#111',
    font:{color:'#eee'},

    shapes: <?= $shapes_json ?>,

    xaxis:{title:'Date'},

    yaxis:{title:'ψ', side:'left'},

    yaxis2:{title:'Fast', overlaying:'y', side:'right'},

    yaxis3:{title:'Slow', overlaying:'y', side:'right', position:0.95},

    yaxis4:{title:'12m RoC', overlaying:'y', side:'right', position:0.9},

    yaxis5:{
        title:'BTC (log)',
        overlaying:'y',
        side:'right',
        position:1.0,
        type:'log'
    },

    legend:{orientation:'h', y:-0.3}
});
</script>

</body>
</html>
