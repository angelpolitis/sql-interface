<?php
    /*/
     * Project Name:    SQL Interface (sqlint)
     * Version:         2.1.4
     * Repository:      https://github.com/angelpolitis/sql-interface
     * Created by:      Angel Politis
     * Creation Date:   Aug 17 2018
     * Last Modified:   Feb 15 2022
    /*/

    /*/
     * NOTES:
     * • secureSQLValue()'s SQL expression tokens ('{', '}') are hardcoded. Make them dynamic and settable.
    /*/

    /**
     * Acts as an interface of communication between PHP and a MySQL database using the MySQLi driver.
     * @method static array configure(array $settings) Sets the query settings (class-specific) to use when querying the database.  
     * These settings can be overriden for each instance individually as well as  
     * for each query when running it.
     * @method array configure(array $settings) Sets the query settings (instance-specific) to use when querying the database.  
     * These settings can be overriden individually for each query when running it.
     * @method static array getCredentials() Gets the credentials (class-specific) used to connect to a database server.  
     * These settings can be overriden for each instance individually.
     * @method array getCredentials() Gets the credentials (instance-specific) used to connect to a database server.
     * @method static array getQuerySettings() Gets the settings (class-specific) used when querying the database.  
     * These settings can be overriden for each instance individually.
     * @method array getQuerySettings() Gets the settings (instance-specific) used when querying the database.  
     * @method static array setCredentials(array $credentials) Sets the credentials (class-specific) used to connect to a database server.  
     * These settings can be overriden for each instance individually.
     * @method array setCredentials(array $credentials) Sets the credentials (instance-specific) used to connect to a database server.
     */
    class SQLInterface {
        /**
         * The next available id to assign an instance of the class.
         * @var int
         */
        protected static $nextId = 1;
        
        /**
         * The default credentials used to establish a connection to the database server.
         * @var array
         */
        protected static $defaultCredentials = [
            "host" => null,
            "username" => null,
            "password" => null,
            "database" => null,
            "port" => null,
            "socket" => null
        ];

        /**
         * The default settings used when querying the database.
         * @var array
         */
        protected static $defaultQuerySettings = [
            "charset" => "utf8",
            "maxRowsUsingInsert" => 1000,
            "noRowsAsArray" => false,
            "omitSingleKey" => false,
            "rowsIndexed" => false,
            "tempDir" => __DIR__,
            "tokens" => ["<%", "%>"],
            "throwErrors" => true
        ];

        /**
         * The active connection to the database.
         * @var mysqli|null
         */
        protected $connection = null;
        
        /**
         * The credentials used to establish a connection to the database server.
         * @var array
         */
        protected $credentials = [];

        /**
         * The contents of SQL files loaded into an instance.
         * @var array
         */
        protected $files = [];

        /**
         * The error that occurred after the execution of the last query.  
         * ```null``` means no error.
         * @var object|null
         */
        protected $lastError = null;

        /**
         * The id of the first entry inserted in the database during the last insertion.
         * @var int
         */
        protected $lastInsertId = 0;

        /**
         * The ids of the all entries inserted in the database during the last insertion.
         * @var array
         */
        protected $lastInsertIds = [];

        /**
         * The log containing all executed queries, as well as their results and any errors, since the
         * execution of the script.
         * @var array
         */
        protected $queryLog = [];
        
        /**
         * The query settings of an instance. They override the default query settings of the class and
         * can be overriden by the settings provided for an individual query.
         * @var array
         */
        protected $querySettings = [];

        /**
         * The result of the last executed query.
         * @var mixed
         */
        protected $result = null;

        /**
         * The saved queries.
         * @var array
         */
        protected $savedQueries = [];
        
        /**
         * Constructs the class.
         * @param array $credentials the credentials used to connect to the database
         * @throws SQLInterfaceMissingDriverException if the MySQLi driver isn't installed
         */
        public function __construct (array $credentials = []) {
            # Throw an exception if the MySQLi driver isn't installed.
            if (!function_exists("mysqli_connect")) throw new SQLInterfaceMissingDriverException();

            # Create a unique id for the SQLInterface instance.
            $this -> id = ++self::$nextId;

            # Set the given credentials.
            $this -> credentials = array_merge(self::$defaultCredentials, array_intersect_key($credentials, self::$defaultCredentials));

            # Initialise the query settings of the interface.
            $this -> querySettings = self::$defaultQuerySettings;
        }

        /**
         * Intercepts calls to non-existent instance methods.
         * @param string $name the name of the method
         * @param array $arguments the arguments passed to the method
         * @return mixed the value returned by the intended target
         * @throws Exception if the intended target wasn't found
         */
        public function __call (string $name, array $arguments) {
            # Check whether the method doesn't exist.
            if (!method_exists($this, "{$name}__i")) {
                # Throw an exception.
                throw new Exception("Call to undefined method '$name'.");
            }

            # Execute the instance method and return the result.
            return $this -> {"{$name}__i"}(...$arguments);
        }

        /**
         * Intercepts calls to non-existent static methods.
         * @param string $name the name of the method
         * @param array $arguments the arguments passed to the method
         * @return mixed the value returned by the intended target
         * @throws Exception if the intended target wasn't found
         */
        public static function __callStatic (string $name, array $arguments) {
            # Check whether the method doesn't exist.
            if (!method_exists(static::class, "{$name}__c")) {
                # Throw an exception.
                throw new Exception("Call to undefined static method '$name'.");
            }

            # Execute the class method and return the result.
            return static::{"{$name}__c"}(...$arguments);
        }

        /**
         * Destructs an instance of the class.
         */
        public function __destruct () {
            # Shut down the database connection.
            $this -> disconnect();
        }
        
        /**
         * Performs a bulk insertion of data in the database using a temporary CSV file.
         * @param array $rows the rows to be inserted in the database
         * @param string $table the name of the table
         * @param array $fields the names of the columns
         * @return SQLInterface the instance
         * @throws Exception if the insertion fails for any reason
         */
        public function bulkInsert (array $rows, string $table, array $fields = []) : SQLInterface {
            # Cache the number of rows.
            $rowCount = sizeof($rows);

            # Encapsulate the fields in backticks.
            $fields = array_map(fn ($field) => "`$field`", $fields);

            # Check whether the maximum number of rows inserted with an INSERT clause is non-negative and the given rows fewer than or equal to it.
            if ($this -> querySettings["maxRowsUsingInsert"] >= 0 && $rowCount <= $this -> querySettings["maxRowsUsingInsert"]) {
                # Assemble the column string.
                $columnString = $fields ? "(" . implode(", ", $fields) . ")" : "";

                # Reduce the rows into a singular string.
                $valueString = array_reduce($rows, function ($result, $row) {
                    # Secure the individual values of the row against SQL injection.
                    $row = array_map("static::secureSQLValue", $row);

                    # Implode the row into a string and return it.
                    return $result . ", (" . implode(", ", $row) . ")";
                }, "");

                # Trim the commaspace on the left of the data string.
                $valueString = ltrim($valueString, ", ");

                # Establish a database connection.
                $this -> connect();

                # Query the database to insert the given rows.
                $this -> query("INSERT INTO `$table` $columnString VALUES $valueString");

                # Return the ids of the inserted rows.
                return $this -> lastInsertIds;
            }
            
            # Define the path to the temporary file.
            $tempFile = preg_replace("#(?:\\|/)$#", "", $this -> querySettings["tempDir"]) . DIRECTORY_SEPARATOR . static::generateUUID() . ".csv";

            # Open the temporary file in write mode.
            $filePointer = fopen($tempFile, 'w');
            
            # Iterate over the rows.
            foreach ($rows as &$row) {
                # Turn all null values into the '\N' character.
                $row = array_map(fn ($field) => $field ?? "\N", $row);

                # Insert the row into the CSV file.
                fputcsv($filePointer, $row);
            }
            
            # Close the temporary file.
            fclose($filePointer);

            # Define the last insert id.
            $lastId = 0;

            # Attempt to execute the following block.
            try {
                # Start a transaction.
                $this -> transact(function () use ($tempFile, $table, $fields) {
                    # Allow the use of loading a local file.
                    $this -> connection -> options(MYSQLI_OPT_LOCAL_INFILE, true);

                    # Cache the query to load the CSV file into the database.
                    $query = "
                        LOAD DATA LOCAL INFILE '$tempFile'
                        INTO TABLE `$table` 
                        FIELDS TERMINATED BY ','
                        OPTIONALLY ENCLOSED BY '\"'
                        LINES TERMINATED BY '\r\n'
                    ";

                    # Alter the query to include the names of the fields, if any are given.
                    if ($fields) $query .= "(" . implode(", ", $fields) . ")";
    
                    # Query the database to load the CSV file into the table.
                    $this -> query($query);
            
                    # Select the last inserted id.
                    $this -> query("SELECT MAX(`id`) FROM `$table`;", ["omitSingleKey" => true]);
                });

                # Cache the result of the query as the last id.
                $lastId = $this -> result;
            }
            catch (Exception $e) {
                # Rethrow the exception.
                throw $e;
            }
            finally {
                # Delete the temporary file.
                unlink($tempFile);
            }

            # Cache the last insert id.
            $this -> lastInsertId = $lastId ?? 0;

            # Calculate all last insert ids.
            $this -> lastInsertIds = $lastId ? range($lastId - $rowCount + 1, $lastId) : [];

            # Alter the query log of the penultimate query to include the last insert id data.
            $this -> queryLog[sizeof($this -> queryLog) - 2] = [
                "lastInsertId" => $this -> lastInsertId,
                "lastInsertIds" => $this -> lastInsertIds
            ];

            # Return the context.
            return $this;
        }
        
        /**
         * Establishes a connection to the database server.
         * @return SQLInterface the instance
         * @throws SQLInterfaceException if a connection can't be established
         */
        public function connect () : SQLInterface {
            # Terminate the function if a connection already exists.
            if ($this -> isConnected()) return $this;

            # Define the error message.
            $errorMessage = "";

            # The function to handle warnings.
            $handleWarning = function ($errno, $errstr) use (&$errorMessage) { 
                # Set the error message to warning.
                $errorMessage = $errstr;
            };

            # Set the class's warning handler to intercept warnings thrown until it's restored.
            set_error_handler($handleWarning, E_WARNING);

            # Create a new MySQLi instance using the credentials (suppress warnings – they'll be picked up by self::isConnected).
            $this -> connection = new mysqli(...array_values($this -> credentials));

            # Restore the error handler.
            restore_error_handler();
            
            # Check whether there isn't an active database connection.
            if (!$this -> isConnected()) {
                # Throw an exception.
                throw new SQLInterfaceException("Couldn't establish a connection to the database server: $errorMessage");
            }
            
            # Return the context.
            return $this;
        }
        
        /**
         * Sets the query settings (class-specific) to use when querying the database.  
         * These settings can be overriden for each instance individually as well as  
         * for each query when running it.
         * @param array $settings the query settings
         */
        protected static function configure__c (array $settings) : void {
            # Normalise the settings and save them.
            self::$defaultQuerySettings = self::normaliseSettings($settings, self::$defaultQuerySettings);
        }
        
        /**
         * Sets the query settings (instance-specific) to use when querying the database.  
         * These settings can be overriden individually for each query when running it.
         * @param array $settings the query settings
         * @return SQLInterface the instance
         */
        protected function configure__i (array $settings) : SQLInterface {
            # Normalise the settings and save them.
            $this -> querySettings = self::normaliseSettings($settings, $this -> querySettings);
    
            # Return the context.
            return $this;
        }

        /**
         * Deletes a loaded file.
         * @param string $name the name of the file to delete
         * @return SQLInterface the instance
         * @throws SQLInterfaceException if the file doesn't exist
         */
        public function deleteFile (string $name = "file") : SQLInterface {
            # Check whether the file doesn't exist.
            if (!isset($this -> files[$name])) {
                # Throw an exception.
                throw new SQLInterfaceException("No file is saved under '$name'.");
            }

            # Delete the saved file.
            unset($this -> files[$name]);

            # Return the context.
            return $this;
        }

        /**
         * Deletes a saved query.
         * @param string $name the name of the query to delete
         * @return SQLInterface the instance
         * @throws SQLInterfaceException if the query doesn't exist
         */
        public function deleteQuery (string $name) : SQLInterface {
            # Check whether the query doesn't exist.
            if (!isset($this -> savedQueries[$name])) {
                # Throw an exception.
                throw new SQLInterfaceException("No query is saved under '$name'.");
            }

            # Delete the saved query.
            unset($this -> savedQueries[$name]);

            # Return the context.
            return $this;
        }
        
        /**
         * Shuts down the database connection.
         * @return SQLInterface the instance
         * @throws SQLInterfaceException if the connection can't be shut down
         */
        public function disconnect () : SQLInterface {
            # Terminate the function if there is no connection.
            if (!$this -> isConnected()) return $this;

            # Close the connection.
            $closed = $this -> connection -> close();

            # Check whether the connection couldn't be closed.
            if (!$closed) {
                # Throw an exception.
                throw new SQLInterfaceException("The connection couldn't be closed because: " . $this -> connection -> error);
            }

            # Nullify the connection property.
            $this -> connection = null;
            
            # Return the context.
            return $this;
        }
        
        /**
         * Executes the content of a loaded SQL file.
         * @param string $fileName the alias the file was loaded under
         * @return SQLInterface the instance
         * @throws SQLInterfaceException if the file doesn't exist
         */
        public function executeFile (string $fileName = "file") : SQLInterface {
            # Check whether no file exists with the given name.
            if (!isset($this -> files[$fileName])) {
                # Decide which message to show based on whether a file name was given.
                $message = func_num_args() > 0 ? "No loaded file was found under '$fileName'." : "The file couldn't be found.";

                # Throw an exception.
                throw new SQLInterfaceException($message);
            }

            # Start a transaction.
            $this -> transact(function () use ($fileName) {
                # Iterate over the loaded content of the context.
                foreach ($this -> files[$fileName] as $query) {
                    # Execute the iterated query.
                    $this -> query($query);
                }
            });
            
            # Return the context.
            return $this;
        }
        
        /**
         * Extracts the values of a string that are encapsulated by a pair of delimiters.
         * @param string $string the string to process
         * @param mixed &$values the extracted values
         * @param array $delimiters the delimiters used to encapsulate values
         * @param string $placeholder the placeholder to replace the extracted values
         * @param bool $throws whether an exception should be thrown instead of exiting gracefully
         * @param int $iterations the maximum number of values to extract
         * @return string the modified string
         */
        public static function extract (string $string, &$values, array $delimiters, string $placeholder = "", bool $throws = false, int $iterations = null) : string {
            # Normalise the values as an array.
            $values = [];

            # A counter to be used in the loop below.
            $counter = 0;
            
            # Nullify the iterations if the number is less than 0.
            $iterations = ($iterations !== null && $iterations < 0) ? null : $iterations;

            # Cache the lengths of the delimiters.
            $delimiterLengths = array_map(fn ($d) => strlen($d), $delimiters);

            /* Repeat until all allowed iterations have been used. */
            while ($iterations === null || $counter < $iterations) {
                # Cache the indices of the delimiters.
                $openingIndex = strpos($string, $delimiters[0]);
                $closingIndex = strpos($string, $delimiters[1]);

                # Normalise the delimiters to turn 'false' into the maximum integer.
                $intOpeningIndex = ($openingIndex === false ? PHP_INT_MAX : $openingIndex);
                $intclosingIndex = ($closingIndex === false ? PHP_INT_MAX : $closingIndex);

                # The function that checks whether there is an unmatched opening delimiter.
                $checkForMissingClosingDelimiter = function () use ($delimiters, $delimiterLengths, $openingIndex, $closingIndex) {
                    # Check whether an opening delimiter was found but a closing one wasn't or was out of place.
                    if ($openingIndex !== false && ($closingIndex === false || $closingIndex < $openingIndex + $delimiterLengths[0])) {
                        # Throw an exception.
                        throw new SQLInterfaceException("Unmatched '{$delimiters[0]}' was found at index $openingIndex.");
                    }
                };

                # The function that checks whether there is an unmatched closing delimiter.
                $checkForMissingOpeningDelimiter = function () use ($delimiters, $openingIndex, $closingIndex) {
                    # Check whether a closing delimiter was found but an opening one wasn't or was out of place.
                    if ($closingIndex !== false && ($openingIndex === false || $openingIndex >= $closingIndex)) {
                        # Throw an exception.
                        throw new SQLInterfaceException("Unmatched '{$delimiters[1]}' was found at index $closingIndex.");
                    }
                };

                # Check for any unmatched delimiters based on the order of the indices and catch any exceptions.
                try {
                    # Check whether the opening index precedes or equals the closing index.
                    if ($intOpeningIndex <= $intclosingIndex)  {
                        $checkForMissingClosingDelimiter();
                        $checkForMissingOpeningDelimiter();
                    }
                    else {
                        $checkForMissingOpeningDelimiter();
                        $checkForMissingClosingDelimiter();
                    }

                    # Break the loop if there is no opening index.
                    if ($openingIndex === false) break;
                }
                catch (SQLInterfaceException $e) {
                    # Rethrow the exception, if appropriate.
                    if ($throws) throw $e;

                    # Break the loop.
                    break;
                }

                # Save the extracted value into the result.
                $values[$openingIndex + $delimiterLengths[0]] = substr($string, $openingIndex + $delimiterLengths[0], $closingIndex - $delimiterLengths[1] - $openingIndex);

                # Save the modified string by keeping the part before the opening delimiter, the placeholder, and the part after the closing delimiter.
                $string = substr($string, 0, $openingIndex)
                    . $placeholder
                    . substr($string, $closingIndex + $delimiterLengths[1], strlen($string) - 1);

                # Increment the counter.
                $counter++;
            }

            # Return the string.
            return $string;
        }

        /**
         * Generates a universally unique identifier.
         * @param bool $trim whether to trim the surrounding braces
         * @return string the UUID
         */
		public static function generateUUID (bool $trim = true) : string {
			# Check whether the 'com_create_guid' function exists.
			if (function_exists("com_create_guid")) {
				# Create the guid and trim, if requested.
				return $trim ? trim(com_create_guid(), "{}") : com_create_guid();
			}

			# Check whether the 'openssl_random_pseudo_bytes' function exists.
			else if (function_exists("openssl_random_pseudo_bytes")) {
				# Generates a string of 16 pseudo-random bytes.
				$data = openssl_random_pseudo_bytes(16);
				
				# Edit the 7nth and 9th bytes.
				$data[6] = chr(ord($data[6]) & 0x0f | 0x40);    // set version to 0100
				$data[8] = chr(ord($data[8]) & 0x3f | 0x80);    // set bits 6-7 to 10
				
				# Format and cache the result.
                $result = vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($data), 4));
                
				# Return the result.
				return $trim ? $result : '{' . $result . '}';
			}
			
			# If none of the above functions is available use a fallback.
			else {
				# Seed the random number generator using microtime.
				mt_srand((double) microtime() * 10000);
				
				# Create a unique lowercase id.
				$charid = strtolower(md5(uniqid(rand(), true)));
				
				# Assemble the parts to generate the uuid and return it.
				return $trim ? "" : "{" . substr($charid,  0,  8) . "-" . substr($charid,  8,  4)
					. "-" . substr($charid, 12,  4) . "-" . substr($charid, 16,  4) . "-"
					. substr($charid, 20, 12) . ($trim ? "" : "}");
			}
		}

        /**
         * Get the active database connection.
         * @return mysqli|null the connection, or ```null``` if there isn't one
         */
        public function getConnection () : ?mysqli {
            # Return the active database connection.
            return $this -> connection;
        }

        /**
         * Gets the credentials (class-specific) used to connect to a database server.
         * @return array the credentials
         */
        protected static function getCredentials__c () : array {
            # Return the default credentials.
            return self::$defaultCredentials;
        }

        /**
         * Gets the credentials (instance-specific) used to connect to a database server.
         * @return array the credentials
         */
        protected function getCredentials__i () : array {
            # Return the credentials.
            return $this -> credentials;
        }

        /**
         * Gets the loaded files.
         * @return array the files
         */
        public function getFiles () : array {
            # Return the files.
            return $this -> files;
        }
        
        /**
         * Gets the error that occurred after the execution of the last query.
         * @return object|null the error, or ```null``` if there isn't one
         */
        public function getLastError () : ?object {
            # Return the last error of the context.
            return $this -> lastError;
        }

        /**
         * Gets the id of the first entry inserted in the database during the last insertion.
         * @return int the id of the entry
         */
        public function getLastInsertId () : int {
            # Return the last insert id.
            return $this -> lastInsertId;
        }

        /**
         * Gets the ids of all entries inserted in the database during the last insertion.
         * @return array the ids of the entries
         */
        public function getLastInsertIds () : array {
            # Return the last insert ids.
            return $this -> lastInsertIds;
        }

        /**
         * Gets the settings (class-specific) used when querying the database.
         * @return array the query settings
         */
        protected static function getQuerySettings__c () : array {
            # Return the default query settings.
            return self::$defaultQuerySettings;
        }

        /**
         * Gets the settings (instance-specific) used when querying the database.
         * @return array the query settings
         */
        protected function getQuerySettings__i () : array {
            # Return the query settings.
            return $this -> querySettings;
        }

        /**
         * Gets the result of the last executed query.
         * @return mixed the result
         */
        public function getResult () {
            # Return the result of the latest query.
            return $this -> result;
        }

        /** 
         * Gets all saved queries.
         * @return array the saved queries
         */
        public function getSavedQueries () : array {
            # Return the saved queries.
            return $this -> savedQueries;
        }

        /**
         * Gets the query log.
         * @return array the query log
         */
        public function getQueryLog () : array {
            # Return the query log.
            return $this -> queryLog;
        }

        /**
         * Returns whether an active database connection exists.
         * @return bool whether an active database connection exists
         */
        public function isConnected () : bool {
            # The flag that signifies that MySQLi couldn't be fetched.
            $unreachable = false;

            # The function to handle warnings in case the MySQLi object can't be fetched.
            $handleWarning = function ($errno, $errstr) use (&$unreachable) { 
                # Set the flag to whether MySQLi is unreachable.
                $unreachable = preg_match("#Couldn't fetch mysqli$#", $errstr);
            };

            # Set the class's warning handler to intercept warnings thrown until it's restored.
            set_error_handler($handleWarning, E_WARNING);
            
            # Attempt to execute the following block.
            try {
                # Cache whether the connection is a MySQLi instance without errors, has a thread ID and is active.
                $isConnected = $this -> connection instanceof mysqli
                    && !$this -> connection -> error
                    && $this -> connection -> thread_id !== false
                    && $this -> connection -> ping();
            }
            catch (Throwable $e) {
                # Assume there's no connection.
                $isConnected = false;
            }

            # Restore the error handler.
            restore_error_handler();

            # Return whether the server is reachable and an active connection exists.
            return !$unreachable && $isConnected;
        }
        
        /**
         * Loads an SQL file.
         * @param string $location the path or URL to the file to be loaded
         * @param string $name a unique identifier given to the file (required for multiple files to be loaded)
         * @return SQLInterface the instance
         * @throws SQLInterfaceException if the file doesn't exist
         */
        public function loadFile (string $location, string $name = "file") : SQLInterface {
            # Check whether the file at the given location doesn't exist or isn't a file.
            if (!is_file($location)) {
                # Throw an exception.
                throw new SQLInterfaceException("The file at the given directory doesn't exist or is inaccessible.");
            }
            
            # Fetch the content of the file at the given location and cache it.
            $this -> files[$name] = @file_get_contents($location);

            # Save a reference to the file.
            $file = &$this -> files[$name];
            
            # Strip the loaded content off all comments and excess whitespace.
            $file = self::trim($file);
            
            # Explode the loaded content at the semi-colons to get an array of queries.
            $file = array_filter(preg_split("/(?<=;)/", $file));
            
            # Return the context.
            return $this;
        }

        /**
         * Returns a normalised version of the settings, while filtering out unknown ones.
         * @param array $settings the settings
         */
        protected static function normaliseSettings (array $settings, array $defaultSettings) : array {
            # Iterate over the settings.
            foreach ($settings as $setting => $value) {
                # Turn the first character of the setting to lowercase.
                $setting = lcfirst($setting);

                # Turn the setting to lowercase and split at the underescores.
                $parts = explode('_', strtolower($setting));

                # Check whether more than one parts exist (aka snake-case is used).
                if (sizeof($parts) > 1) {
                    # Turn a snake-case name into camel-case.
                    $setting = lcfirst(implode("", array_map(fn ($c) => ucfirst($c), $parts)));
                }

                # Skip the current iteration if the setting isn't part of the default settings.
                if (!array_key_exists($setting, self::$defaultQuerySettings)) continue;

                # Reinsert the setting again under the new name.
                $settings[$setting] = $value;
            }

            # Return the settings in addition to the default ones for any that are missing.
            return $settings + $defaultSettings;
        }
        
        /**
         * Queries the database (requires an active connection).
         * @param string $query the query to communicate to the database
         * @param array $settings the query settings (override ```$querySettings``` and ```$defaultQuerySettings```)
         * @return SQLInterface the instance
         * @throws SQLInterfaceNoConnectionException if there's no active database connection
         * @throws SQLInterfaceStatementPreparationFailureException if the preparation of a statement fails
         * @throws SQLInterfaceStatementExecutionFailureException if the execution of a statement fails
         * @throws SQLInterfaceQueryExecutionErrorException if the execution of a non-parameterised query results in an error
         */
        public function query (string $query, array $settings = []) : SQLInterface {
            # Check whether there isn't an active database connection.
            if (!$this -> isConnected()) {
                # Throw an exception.
                throw new SQLInterfaceNoConnectionException();
            }

            # Normalise the query settings.
            $settings = self::normaliseSettings($settings, $this -> querySettings);

            # The arrays that will hold the values and types of the parameters.
            $values = [];
            $types = [];

            # Trim the query of unnecessary content.
            $query = self::trim($query);

            # Extract the values to be parameterised and save the modified query.
            $query = self::extract($query, $values, $settings["tokens"], '?', true);

            # Define the cumulative shift of the query.
            $cumulativeIndexShift = 0;

            # Iterate over the extracted values.
            foreach ($values as $index => &$value) {
                # Check whether the value is an integer.
                if ($value === strval(intval($value))) {
                    # Conside the value an integer.
                    $types[] = 'i';

                    # Turn the value into an integer.
                    $value = intval($value);
                }

                # Check whether the value is a double.
                elseif ($value === strval(doubleval($value))) {
                    # Conside the value an integer.
                    $types[] = 'd';

                    # Turn the value into a double.
                    $value = doubleval($value);
                }

                # Check whether the value is the empty string or 'null'.
                elseif ($value === "" || strtolower($value) === "null") {
                    # Define the value to be injected into the query.
                    $injectedValue = $value === "" ? "''" : "NULL";

                    # Unset the value from the array.
                    unset($values[$index]);

                    # Increment the index by the cumulative shift, if any.
                    $index += $cumulativeIndexShift;

                    # Remove the placeholder from the query and add NULL, as this value can't be parameterised.
                    $query = substr($query, 0, $index - 1) . $injectedValue . substr($query, $index);

                    # Increment the cumulative shift by the length of the injected value.
                    $cumulativeIndexShift += max(0, strlen($injectedValue) - 1);
                }

                # In any other case consider the value a string.
                else $types[] = 's';
            }

            # Unset the value reference.
            unset($value);

            # Set the charset of the connection.
            $this -> connection -> set_charset($settings["charset"]);

            # Reset the last error.
            $this -> lastError = null;

            # Cache the index of the new entry to the query log.
            $queryLogIndex = sizeof($this -> queryLog);

            # Insert the query into the log.
            $this -> queryLog[$queryLogIndex] = [
                "query" => $query,
                "database" => $this -> credentials["database"],
                "settings" => $settings,
                "parameters" => array_values($values),
                "types" => $types,
                "error" => null
            ];
            
            # Check whether no values were extracted.
            if (sizeof($values) === 0) {
                # Query the database and cache the result.
                $queryResult = $this -> connection -> query($query);

                # Check whether there is an error.
                if ($this -> connection -> error) {
                    # Set the last error.
                    $this -> setLastError();

                    # Insert the error into the query log.
                    $this -> queryLog[$queryLogIndex]["error"] = $this -> lastError;

                    # Throw an exception, if appropriate.
                    if ($settings["throwErrors"]) throw new SQLInterfaceQueryExecutionErrorException($this);
                }
                
                # Cache the number of affected rows into the log.
                $this -> queryLog[$queryLogIndex]["affectedRows"] = $this -> connection -> affected_rows;
            }
            else {
                # Prepare a statement out of the query.
                $stmt = $this -> connection -> prepare($query);

                # Check whether the preparation failed.
                if (!$stmt) {
                    # Set the last error.
                    $this -> setLastError();

                    # Insert the error into the query log.
                    $this -> queryLog[$queryLogIndex]["error"] = $this -> lastError;

                    # Throw an exception, if appropriate.
                    if ($settings["throwErrors"]) throw new SQLInterfaceStatementPreparationFailureException($this);
                }

                # Create the arguments array that will be passed to the 'bind_params' function.
                $typeString = implode("", $types);
                $arguments = array_merge([&$typeString], array_values($values));

                # Bind the parameters to the statement.
                $stmt -> bind_param(...$arguments);

                # Execute the statement.
                $executionResult = $stmt -> execute();

                # Check whether the execution failed.
                if (!$executionResult) {
                    # Set the last error.
                    $this -> setLastError();

                    # Insert the error into the query log.
                    $this -> queryLog[$queryLogIndex]["error"] = $this -> lastError;

                    # Throw an exception, if appropriate.
                    if ($settings["throwErrors"]) throw new SQLInterfaceStatementExecutionFailureException($this);
                }
                
                # Cache the number of affected rows into the log.
                $this -> queryLog[$queryLogIndex]["affectedRows"] = $stmt -> affected_rows;
                
                # Get the result out of the statement and store it.
                $queryResult = $stmt -> get_result();

                # Close the statement.
                $stmt -> close();
            }

            # Create an array to store the rows.
            $result = [];

            # Check whether the result isn't a boolean.
            if (!is_bool($queryResult)) {
                # Cache the number of rows found.
                $rows = $queryResult -> num_rows;

                # Check whether one or more rows were found.
                if ($rows >= 1) {
                    # Iterate over the rows.
                    while ($row = $queryResult -> fetch_assoc()) {
                        # Cache the value of the column directly, if only one column was fetched and the appropriate setting is set.
                        $row = (sizeof($row) === 1 && $settings["omitSingleKey"]) ? $row[key($row)] : $row;

                        # Insert the value in the result.
                        $result[] = $row;
                    }

                    # Check whether one row was found and rows should not be indexed.
                    if ($rows === 1 && !$settings["rowsIndexed"]) {
                        # Assign the first result to the result.
                        $result = $result[0];
                    }
                }

                # If no rows were found, set the result to an empty array or false.
                else $result = $settings["noRowsAsArray"] ? [] : false;

                # Set the internal pointer of the result to the first entry.
                $queryResult -> data_seek(0);

                # Add the number of rows into the log.
                $this -> queryLog[$queryLogIndex]["rows"] = $rows;
            }
            else {
                # Overwrite the result array to store the result of the query.
                $result = (sizeof($values) === 0) ? $queryResult : !isset($this -> lastError);

                # Cache the last insert id.
                $lastId = $this -> connection -> insert_id;

                # Cache the affected rows.
                $affectedRows = $this -> queryLog[$queryLogIndex]["affectedRows"];

                # Insert the last insert id into the log.
                $this -> queryLog[$queryLogIndex]["lastInsertId"] = $lastId;

                # Check whether there is a last insert id.
                if ($lastId) {
                    # Check whether the number of affected rows is greater than 1.
                    if ($affectedRows > 1) {
                        # Calculate the ids of all inserted rows.
                        $this -> lastInsertIds = range($lastId, $lastId + $affectedRows - 1);
                    }
                    else {
                        # Insert the last insert id as the only id into the ids array.
                        $this -> lastInsertIds = [$lastId];
                    }

                    # Add the last insert ids to the log.
                    $this -> queryLog[$queryLogIndex]["lastInsertIds"] = $this -> lastInsertIds;
                }
            }
            
            # Cache the result into the instance.
            $this -> result = $result;

            # Add the result into the log.
            $this -> queryLog[$queryLogIndex]["result"] = $this -> result;

            # Cache the last insert ID of the connection.
            $this -> lastInsertId = $this -> connection -> insert_id;
            
            # Return the context.
            return $this;
        }

        /**
         * Runs a saved query.
         * @param string $name the name of the query to run
         * @return SQLInterface the instance
         * @throws SQLInterfaceException if the query doesn't exist
         */
        public function runQuery (string $name) : SQLInterface {
            # Cache the saved query.
            $query = $this -> savedQueries[$name] ?? null;

            # Check whether the query doesn't exist.
            if (is_null($query)) {
                # Throw an exception.
                throw new SQLInterfaceException("No query is saved under '$name'.");
            }

            # Execute the query with its settings.
            return $this -> query($query["query"], $query["settings"]);
        }

        /**
         * Saves a query for later use.
         * @param string $name the name to save the query under
         * @param string $query the SQL query
         * @param array $settings the setting to be used when running the query
         * @return SQLInterface the instance
         */
        public function saveQuery (string $name, string $query, array $settings = []) : SQLInterface {
            # Insert the query and its settings into the saved queries.
            $this -> savedQueries[$name] = [
                "query" => $query,
                "settings" => self::normaliseSettings($settings, $this -> querySettings)
            ];

            # Return the context.
            return $this;
        }

        /**
         * Secures a value according to SQLInterface specifications.
         * @param mixed $value the value to secure
         * @return string the value
         */
        public static function secureSQLValue ($value) : string {
            # Check whether the value is a string surrounded by curly braces.
            if (is_string($value) && $value[0] === '{' && substr($value, -1) === '}') {
                # Return the string without the braces.
                return substr($value, 1, -1);
            }

            # Return a pair of single quotes if the value is the empty string.
            if ($value === "") return "''";

            # Return null if the value is null.
            if ($value === null) return "NULL";

            # Turn the value into a number if it's a bool.
            if (is_bool($value)) $value = +$value;

            # Cache the default security tokens.
            $tokens = self::$defaultQuerySettings["tokens"];

            # Return the value encapsulated in a pair of security tokens.
            return $tokens[0] . $value . $tokens[1];
        }

        /**
         * Sets the credentials used to connect to a database server (class-specific).
         * @param mixed $host the host name (```string```) or an ```array``` setting everything
         * @param string $username the username to use
         * @param string $password the password to use
         * @param string $database the database to connect to
         * @param int $port the port of the database server to use
         * @param string $socket the socket to use
         */
        protected static function setCredentials__c ($host = null, ?string $username = null, ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null) : void {
            # Create an array that houses all the information regardless of how it has been given.
            $data = is_array($host)
                ? array_combine(array_map(fn ($key) => strtolower($key), array_keys($host)), $host)
                : compact("host", "username", "password", "database", "port", "socket");

            # Keep only the relevant keys, fill any missing values and update the default credentials.
            self::$defaultCredentials = array_merge(self::$defaultCredentials, array_intersect_key($data, self::$defaultCredentials));
        }

        /**
         * Sets the credentials used to connect to a database server (instance-specific).
         * @param mixed $host the host name (```string```) or an ```array``` setting everything
         * @param string $username the username to use
         * @param string $password the password to use
         * @param string $database the database to connect to
         * @param int $port the port of the database server to use
         * @param string $socket the socket to use
         * @return SQLInterface the instance
         */
        protected function setCredentials__i ($host = null, ?string $username = null, ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null) : SQLInterface {
            # Create an array that houses all the information regardless of how it has been given.
            $data = is_array($host)
                ? array_combine(array_map(fn ($key) => strtolower($key), array_keys($host)), $host)
                : compact("host", "username", "password", "database", "port", "socket");

            # Keep only the relevant keys, fill any missing values and update the credentials.
            $this -> credentials = array_merge(self::$defaultCredentials, array_intersect_key($data, $this -> credentials));

            # Return the context.
            return $this;
        }

        /**
         * Sets the database credential, and also changes the active database if there is a connection.
         * @param string $database the name of the database
         * @return SQLInterface the instance
         * @throws SQLInterfaceNoConnectionException if there's no active database connection
         */
        public function setDatabase (string $database) : SQLInterface {
            # Set the new credential.
            $this -> credentials["database"] = $database;

            # Throw an exception if there isn't an active database connection.
            if (!$this -> isConnected()) throw new SQLInterfaceNoConnectionException();

            # Select the given database.
            $this -> connection -> select_db($database);

            # Return the context.
            return $this;
        }

        /**
         * Sets the last error.
         * @return SQLInterface the instance
         */
        protected function setLastError () : SQLInterface {
            # Cache the connection error as the latest error.
            $this -> lastError = (object) [
                "code" => $this -> connection -> errno,
                "message" => $this -> connection -> error
            ];

            # Return the context.
            return $this;
        }

        /**
         * Starts and carries out a transaction.
         * @param callable $operation a function interacting with the database
         * @param bool $throws whether the operation can throw exceptions
         * @param bool &$returnValue the return value of the operation
         * @return SQLInterface the instance
         */
        public function transact (callable $operation, bool $throws = true, &$returnValue = null) : SQLInterface {
            # --- Work with the backtrace to see if this transaction is part of another transaction --- #

            # Cache the backtrace minus the first result (since it's this function).
            $backtrace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1);

            # Cache the name of the current function.
            $functionName = __FUNCTION__;

            # The function that returns whether a given backtrace entry is the current function.
            $isCurrentFunction = fn ($entry) => ($entry["class"] ?? "") === self::class && ($entry["function"] ?? "") === $functionName;

            # Cache whether this function has been called inside another call of this function.
            $transactionInTransaction = sizeof(array_filter($backtrace, $isCurrentFunction)) > 0;

            # --- Define the functions to commit, rollback and create save points --- #

            # The flag that determines whether the transaction can be commited.
            $canCommit = true;

            # The function that commits a transaction.
            $commit = function () use (&$canCommit) {
                # Commit the transaction.
                $this -> connection -> commit();

                # Prevent the transaction from being committed again.
                $canCommit = false;
            };
            
            # The function that rolls back a transaction.
            $rollback = function (string $savePoint = null) use (&$canCommit) {
                # Roll back the transaction.
                $this -> query("ROLLBACK" . (is_null($savePoint) ? "" : " TO `$savePoint`"));

                # Prevent the transaction from being committed if no save point was given.
                $canCommit = !is_null($savePoint);
            };

            # The function that defines a new save point.
            $savePoint = fn (string $name) => $this -> query("SAVEPOINT `$name`");
            
            # --- Implement the transaction --- #

            # Define the result of the operation.
            $returnValue = null;

            # Establish a database connection.
            $this -> connect();

            # Query the database to find the current value of autocommit.
            $this -> query("SELECT @@autocommit as `v`", [
                "omitSingleKey" => true,
                "rowsIndexed" => false
            ]);

            # Remove the last entry from the query log (the above query).
            array_pop($this -> queryLog);

            # Cache the result.
            $autocommit = boolval(+$this -> getResult());

            # Check whether this transaction is part of another transaction.
            if ($transactionInTransaction) {
                # Generate a unique nane using the date.
                $sp = "sp_" . time();

                # The function that rolls back a transaction.
                $rollback = function (string $savePoint = null) use ($sp) {
                    # Roll back the transaction.
                    $this -> query("ROLLBACK" . (is_null($savePoint) ? "" : (($savePoint === ":this") ? " TO `$sp`" : " TO `$savePoint`")));
                };

                # Create a save point.
                $savePoint($sp);

                # Attempt to execute the following block.
                try {
                    # Execute the given operation.
                    $returnValue = $operation($commit, $rollback, $savePoint);
                }
                catch (Throwable $e) {
                    # Establish a database connection.
                    $this -> connect();

                    # Roll back the transaction.
                    $rollback($sp);

                    # Rethrow the exception, if allowed.
                    if ($throws) throw $e;
                }
            }
            else {
                # Instruct the database to only commit the changes when told so.
                $this -> connection -> autocommit(false);
    
                # Start a new transaction.
                $this -> connection -> begin_transaction();
                
                # Attempt to execute the following block.
                try {
                    # Execute the given operation.
                    $returnValue = $operation($commit, $rollback, $savePoint);
                }
                catch (Throwable $e) {
                    # Establish a database connection.
                    $this -> connect();

                    # Roll back the transaction.
                    $rollback();
                }
    
                # Commit the transaction if it can be committed.
                if ($canCommit) $commit();
        
                # Restore the former autocommit status.
                $this -> connection -> autocommit($autocommit);
    
                # Rethrow the exception, if allowed.
                if ($throws && isset($e)) throw $e;
            }

            # Return the context.
            return $this;
        }

        /**
         * Strips all comments and excess whitespace from a text.
         * @param string $text the text to make lean
         * @return string the modified text
         */
        protected static function trim (string $text) : string {
            # Remove all single-line comments.
            $text = preg_replace("/((?:-- |#)[^\n]*[\n]+)|((\s*)\/\*([^\/]*)\*\/(\s*))/si", "", $text);

            # Remove all multi-line comments.
            $text = preg_replace("/\/\*[\s\S]*?\*\/|([^\\:]|^)\/\/.*$/m", "$1", $text);

            # Remove all excess whitespace everywhere except if enclosed in backticks or single quotes.
            $text = preg_replace("/\s+(?=([^`']*[`'][^`']*[`'])*[^`']*$)/", " ", $text);

            # Remove all excess whitespace in the vicinity of commas.
            $text = preg_replace("/,\s*/", ", ", $text);

            # Remove all excess whitespace after opening parenthesis and before closing parenthesis.
            $text = preg_replace("/(?:(\()\s+)|(?:\s+(\)))/", "$1$2", $text);

            # Return the modified text.
            return trim($text);
        }
    }

    /**
     * The basic exception class of SQLInterface.
     */
    class SQLInterfaceException extends Exception {};

    /**
     * An exception thrown when an error occurs in the middle of MySQLi connection.
     */
    class SQLInterfaceConnectionErrorException extends SQLInterfaceException {
        /**
         * The message of the exception.
         * @var string
         */
        protected $message = "Connection error!";

        /**
         * Constructs the class.
         * @param SQLInterface an SQLInterface instance
         */
        public function __construct (SQLInterface $interface) {
            # Get the last error of the interface.
            $error = $interface -> getLastError();

            # End the function if there is no error.
            if (!$error) return;

            # Save the code of the last error.
            $this -> code = $error -> code;

            # Alter the message to include that of the error.
            $this -> message = substr($this -> message, 0, -1) . ": " . $error -> message;
        }
    };

    /**
     * An exception thrown when the MySQLi driver isn't installed.
     */
    class SQLInterfaceMissingDriverException extends SQLInterfaceException {
        /**
         * The message of the exception.
         * @var string
         */
        protected $message = "SQLInterface requires the MySQLi driver to work!";
    };

    /**
     * An exception thrown when a MySQLi connection is required but doesn't exist.
     */
    class SQLInterfaceNoConnectionException extends SQLInterfaceException {
        /**
         * The message of the exception.
         * @var string
         */
        protected $message = "An active database connection is required to send a query.";
    };

    /**
     * An exception thrown when a MySQLi statement can't be prepared.
     */
    class SQLInterfaceStatementPreparationFailureException extends SQLInterfaceConnectionErrorException {
        /**
         * The message of the exception.
         * @var string
         */
        protected $message = "Statement preparation failed!";
    };

    /**
     * An exception thrown when a MySQLi prepared statement can't be executed.
     */
    class SQLInterfaceStatementExecutionFailureException extends SQLInterfaceConnectionErrorException {
        /**
         * The message of the exception.
         * @var string
         */
        protected $message = "Statement execution failed!";
    };

    /**
     * An exception thrown when a MySQLi query results in an error.
     */
    class SQLInterfaceQueryExecutionErrorException extends SQLInterfaceConnectionErrorException {
        /**
         * The message of the exception.
         * @var string
         */
        protected $message = "Query execution failed!";
    };
?>
