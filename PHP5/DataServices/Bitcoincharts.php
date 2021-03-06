<?php 

namespace CryptoTraderHub\DataServices;

class Bitcoincharts extends \CryptoTraderHub\DataServices\DataService implements \CryptoTraderHub\DataServices\DataServiceInterface{
		
	// Constructor
	public function __construct() {		
		parent::__construct();		
	}
	
	/* 
	 * You can use this to price goods and services in Bitcoins. 
	 * This will yield much lower fluctuations than using a single market's latest price.
	 * Weighted prices are calculated for the last 24 hours, 7 days and 30 days. If 
	 * there are no trades during an interval (like no trade within 24 hours) no value will 
	 * be returned. Prepare your code to handle this cases!	The returned JSON is dictionary
	 * with elements for each currency. Each currency has up to three key-value pairs: 24h, 7d and 30d. 
	*/
	public function weightedPrices(){	
		
		$url 	= 'http://http://api.bitcoincharts.com/v1/weighted_prices.json';
		$method = 'GET';
		$data 	= Array();
		
		return \CryptoTraderHub\Core\Connection::request($url, $method, $data);	
	}	

	/* 
	 * This will return an array with elements for each market. Returned fields per market are:
	 * symbol 			- short name for market
	 * currency 		- base currency of the market (USD, EUR, RUB, JPY, ...)
	 * bid 				- highest bid price
	 * ask 				- lowest ask price
	 * latest_trade 	- unixtime of latest trade. Following fields relate to the day of this field (UTC)!
	 * n_trades 		- number of trades
	 * high 			- highest trade during day
	 * low 				- lowest trade during day
	 * close 			- latest trade
	 * previous_close 	- latest trade of previous day
	 * volume 			- total trade volume of day in BTC
	 * currency_volume	- total trade volume of day in currency 
	 */
	public function marketsData(){
	
		$url 	= 'http://api.bitcoincharts.com/v1/markets.json';
		$method = 'GET';
		$data 	= Array();
	
		return \CryptoTraderHub\Core\Connection::request($url, $method, $data);
	}		

	/* 
	 * Trade data is available as CSV, delayed by approx. 15 minutes. It will return the 2000 most recent trades.
	 * http://api.bitcoincharts.com/v1/trades.csv?symbol=bitstampUSD[&start=UNIXTIME]
	 */
	public function historicData($symbol, $start = null){
	
		$url 	= 'http://api.bitcoincharts.com/v1/trades.csv';
		$method = 'GET';
		$data 	= Array('symbol' => $symbol, 'start' => $start);
	
		return \CryptoTraderHub\Core\Connection::request($url, $method, $data);
	}	
	
	/* 
	 * Complete history. This will require a lot of memory. Consider manually downloading 
	 * these files via: http://api.bitcoincharts.com/v1/csv
	 * Returns the name of the file
	 */ 
	public function completeHistory($symbol, $load_into_database = true){
		
		// Delete the old history
		if (is_readable(APP_TMP . '/'.$symbol.'.csv')){
			unlink(APP_TMP . '/'.$symbol.'.csv');
		}
		
		// Attempt to download the entire history for an excahnge in a certain currency
		if(($file_handle = fopen (APP_TMP . '/'.$symbol.'.csv.gz', 'w+')) !== false){
			$url 	= 'http://api.bitcoincharts.com/v1/csv/'.$symbol.'.csv.gz';
			$method = 'GET';
			$data 	= Array();
			$options = Array(
				'CURLOPT_FILE' => $file_handle,
			);
		
			\CryptoTraderHub\Core\Connection::request($url, $method, $data, $options);			
			fclose($file_handle);
			
			$buffer_size = 262144;
			$in_file_handle = gzopen(APP_TMP . '/'.$symbol.'.csv.gz', 'rb');
			$out_file_handle = fopen(APP_TMP . '/'.$symbol.'.csv', 'wb');
			// Keep repeating until the end of the input file
			while(!gzeof($in_file_handle)) {
				fwrite($out_file_handle, gzread($in_file_handle, $buffer_size));
			}
			// Files are done, close files
			fclose($out_file_handle);
			gzclose($in_file_handle);
			unlink(APP_TMP . '/'.$symbol.'.csv.gz');
			
		}else{
			return false;
		}		
		
		// Insert into the database
		if($load_into_database === true){
			
			// Now that we've downloaded the history, insert into the database
			if(($file_handle = fopen(APP_TMP . '/'.$symbol.'.csv', 'r')) !== false){	 			
				  	
				// Drop then create the table to start anew
			    $this->createHistoryTable($symbol);		
			    
				$sql = "";
				
				// Loop through the file line-by-line nad insert
			    for($index = 0; ($data = fgetcsv($file_handle)) !== false; $index++){			    	
			    	$sql .= "('".date('Y-m-d H:i:00', $data[0])."',{$data[1]},{$data[2]}),";
			       	if($index % 10000 == 0){		       		
						\CryptoTraderHub\Core\Database::runQuery("INSERT INTO {$symbol} (`date`,`price`,`volume`) VALUES " . rtrim($sql,','). " ON DUPLICATE KEY UPDATE `volume`=`volume`+VALUES(`volume`);");
						$sql = "";
			       	}	   
			    }
				// Make sure to insert and remaining data
				if($sql != ""){
					\CryptoTraderHub\Core\Database::runQuery("INSERT INTO {$symbol} (`date`,`price`,`volume`) VALUES " . rtrim($sql,','). " ON DUPLICATE KEY UPDATE `volume`=`volume`+VALUES(`volume`);");
				}
			    fclose($file_handle);
			}
		}		
		return $symbol.'.tmp';
	}
	
	/* 
	 * Helper function that creates a table in the crypto_trader_hub database that 
	 * will be used to store market history
	 */ 
	private function createHistoryTable($symbol){
		
		// Drop the table if it exists
		$sql = "DROP TABLE IF EXISTS `{$symbol}`;";		
		\CryptoTraderHub\Core\Database::runQuery($sql);
		
		// Re-create the table if it exists with new data
		$sql = 
		"CREATE TABLE `{$symbol}` (
			`{$symbol}_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			`date` DATETIME NOT NULL,
			`price` DOUBLE NOT NULL,
			`volume` DOUBLE NOT NULL,
			PRIMARY KEY (`{$symbol}_id`),
			UNIQUE INDEX `date` (`date`),
			INDEX `price` (`price`)
		)
		COLLATE='utf8_general_ci'
		ENGINE=InnoDB
		;";		
		\CryptoTraderHub\Core\Database::runQuery($sql);
		
	}
	
}
