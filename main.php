<?php
require "vendor/autoload.php";
$dataDir = getenv('KBC_DATADIR') . DIRECTORY_SEPARATOR;
$configFile = $dataDir . 'config.json';
$config = json_decode(file_get_contents($configFile), true);

$outFile = new \Keboola\Csv\CsvFile(
    $dataDir . 'out' . DIRECTORY_SEPARATOR . 'tables' . DIRECTORY_SEPARATOR . 'currencies.csv'
);
$outFile->writeRow(['year', 'month', 'value', 'currency']);

$errors = [];

if (!isset($config['parameters']) || !$config['parameters']) {
    echo 'Missing config parameters';
    exit(1);
}

if (!isset($config['parameters']['currencies'])) {
    echo 'Missing currency';
    exit(1);
}

try {
    function getAverageExchangeRatesFor($currency) {
        global $errors;

        $statusCode = 200;
        $url = "https://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_devizoveho_trhu/prumerne_mena.txt?mena=" . $currency;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        try {
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } catch (Exception $exception) {
            $error .= ' - ' . $exception;
        }
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 400) {
            $errors[] = $error;
            return;
        }
        if (trim($result) == '') {
            $errors[] = 'Wrong currency: ' . $currency;
            return;
        }
        parseExchangeRates($result, $currency);
    }

    function parseExchangeRates($rates, $currency) {
        global $outFile;

        $explode = explode("rok", $rates);
        $rows = preg_split("/\n/", "rok" . $explode[1]);
        foreach ($rows as $rkey => $row) {
            if ($rkey == 0) {
                continue;
            }
            $csv = str_getcsv($row, '|');
            $year = $csv[0];
            foreach ($csv as $month => $v) {
                if ($month == 0) {
                    continue;
                }
                $value = str_replace(",", ".", $v);
                $outFile->writeRow([$year, $month, $value, $currency]);
            }
        }
    }

    if (is_string($config['parameters']['currencies'])) {
        getAverageExchangeRatesFor($config['parameters']['currencies']);
    } else if (is_array($config['parameters']['currencies'])) {
        foreach ($config['parameters']['currencies'] as $currency) {
            getAverageExchangeRatesFor($currency);
        }
    }
} catch (InvalidArgumentException $e) {
    echo $e->getMessage();
    exit(1);
} catch (\Throwable $e) {
    echo $e->getMessage();
    exit(2);
}