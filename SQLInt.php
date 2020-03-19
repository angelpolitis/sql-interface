<?php
    /*/
     * Project Name:    SQL Interface (sqlint)
     * Version:         1.5
     * Repository:      https://github.com/angelpolitis/sql-interface
     * Created by:      Angel Politis
     * Creation Date:   Aug 17 2018
     * Last Modified:   Mar 19 2020
    /*/

    # The SQLInterface class.
    class SQLInterface {
        # A private counter used to assign a unique id to each instance of the class.
        private static $counter = 0;
        
        # A private array containing the necessary credentials for the class to establish a database connection.
        private static $default_credentials = ["host" => "localhost", "username" => "root", "password" => "", "database" => ""];
        
        # A private array containing the necessary settings regarding the 'query' method.
        private static $default_query_settings = ["charset" => "utf8", "show_errors" => false, "rows_indexed" => false, "no_rows_as_array" => false];

        # A private array containing the security tokens.
        private static $security_tokens = ["<%", "%>"];
        
        # A private variable used to store instance-specific credentials.
        private $credentials = null;
        
        # A private variable used to store settings regarding the 'query' method.
        private $query_settings = null;
        
        # The private function used to decapsulate a given string from its delimiters.
        private static function decapsulate ($string, $delimiters, $all = false, $show_errors = false) {
            # A counter and the array that will be returned after the function is complete.
            $counter = 0;
            $result = ["extracted" => [], "modified" => null];

            # The recursive function that generates the results.
            $modify = function ($string) use ($delimiters, $all, $show_errors, &$result, &$counter, &$modify) {
                # Increment the counter.
                $counter++;

                # Check whether the opening delimiter exists.
                if (($opening = strpos($string, $delimiters[0])) !== false) {
                    # Check whether the closing delimiter exists.
                    if (($closing = strpos($string, $delimiters[1], $opening + 2)) !== false) {
                        # Save the extracted and modified values.
                        $result["extracted"][] = substr($string, $opening + strlen($delimiters[0]), $closing - strlen($delimiters[1]) - $opening);
                        $result["modified"] = substr($string, 0, $opening) . ((count($delimiters) > 2) ? $delimiters[2] : "") . substr($string, $closing + strlen($delimiters[1]), strlen($string) - 1);

                        # Check whether total decapsulation is enabled and return the appropriate data.
                        return (!!$all) ? $modify($result["modified"]) : ["extracted" => $result["extracted"][0], "modified" => $result["modified"]];
                    }

                    # If the delimiter isn't found the first time return a warning, otherwise return what's been processed.
                    else return ($counter === 1 && !$result["modified"]) ?  ((!!$show_errors) ? "Closing delimiter wasn't found!" : false) : $result;
                }

                # If the delimiter isn't found the first time return a warning, otherwise return what's been processed.
                else return ($counter === 1 && !$result["modified"]) ? ((!!$show_errors) ? "Opening delimiter wasn't found!" : false) : $result;
            };

            # Run the function 'modify' to analyse and decapsulate the data given.
            return $modify($string);
        }

        # The public function that sets the given tokens as the security tokens for the class.
		public static function set_security_tokens ($token1, $token2) {
			# Update the secutity tokens with the ones given.
			self::$security_tokens = [$token1, $token2];
		}
        
        # The constructor function of the class.
        public function __construct () {
            # Create a unique id for the SQLInterface instance.
            $this -> id = ++self::$counter;
        }
        
        # The function that uses the credentials of the instance or the default ones to initiate a database connection.
        public function connect () {
            # Fetch the correct credentials.
            $credentials = isset($this -> credentials) ? $this -> credentials : self::$default_credentials;
            
            # Create a new MySQLi instance using the credentials.
            $this -> connection = new mysqli(...array_values($credentials)) or die(__METHOD__ . " → The system failed to establish a connection due to the following error: " . $this -> connection -> error);
            
            # Return the context.
            return $this;
        }
        
        # The function that disconnects from an active database connection.
        public function disconnect () {
            # Create a new MySQLi instance using the credentials.
            $this -> connection -> close() or die(__METHOD__ . " → The system failed to shut down the connection due to the following error: " . $this -> connection -> error);
            
            # Return the context.
            return $this;
        }
        
        # The function that gets/sets credentials to the instance or the class.
        public function credentials ($host = null, $username = null, $password = null, $database = null) {
            # Check whether any arguments hasve been given.
            if (func_num_args()) {
                # Create an array that houses all the information regardless of how it has been given.
                $data = is_array($host) ? $host : [
                    "host" => $host,
                    "username" => $username,
                    "password" => $password,
                    "database" => $database
                ];

                # Check whether the function was called by the context.
                if (isset($this) && $this instanceof self) {
                    # Check whether the credentials of the context have been set before.
                    if (isset($this -> credentials)) {
                        # Iterate over every property of the credentials.
                        foreach ($this -> credentials as $key => $value) {
                            # Set the value to the existent credential if it's not set.
                            $data[$key] = isset($data[$key]) ? $data[$key] : $this -> credentials[$key];
                        }
                    }
                    else {
                        # Iterate over every property of the default credentials.
                        foreach (self::$default_credentials as $key => $value) {
                            # Set the value to the existent default credential if it's not set.
                            $data[$key] = isset($data[$key]) ? $data[$key] : self::$default_credentials[$key];
                        }
                    }

                    # Use the default credentials to sort the data array's key in the proper order.
                    $data = array_merge(self::$default_credentials, $data);

                    # Set the data array to the 'credentials' property of the context.
                    $this -> credentials = $data;

                    # Return the context.
                    return $this;
                }
                else {
                    # Iterate over every property of the default credentials.
                    foreach (self::$default_credentials as $key => $value) {
                        # Set the value to the existent default credential if it's not set.
                        $data[$key] = isset($data[$key]) ? $data[$key] : self::$default_credentials[$key];
                    }

                    # Use the default credentials to sort the data array's key in the proper order.
                    $data = array_merge(self::$default_credentials, $data);

                    # Set the data array to the 'default_credentials' property of the class.
                    self::$default_credentials = $data;
                }
            }
            else {
                # Check whether the function was called by the context.
                if (isset($this) && $this instanceof self) {
                    # Return the credentials, if set, or the default credentials.
                    return isset($this -> credentials) ? $this -> credentials : self::$default_credentials;
                }
                else {
                    # Return the default credentials.
                    return self::$default_credentials;
                }
            }
        }
            
        # The function that loads an SQL file from a given link.
        public function load ($url) {
            # Check whether the file at the given location exists.
            if (file_exists($url)) {
                # Fetch the content file at the given link and cache it.
                $this -> content = file_get_contents($url);
                
                # Strip the loaded content off all comments.
                $this -> content = preg_replace("/((?:-- |#)[^\n]*[\n]+)|((\s*)\/\*([^\/]*)\*\/(\s*))/si", "", $this -> content);
                
                # Explode the loaded content at the semi-colons to get an array of queries.
                $this -> content = array_filter(preg_split("/(?<=;)/", $this -> content));
            }
            else {
                # Throw an exception.
                throw new Exception(__METHOD__ . " → The file at the given directory doesn't exist or is inaccessible.");
            }
            
            # Return the context.
            return $this;
        }
        
        # The function that executes the content loaded into an instance.
        public function execute () {
            # Create a variable to store the results of the operation.
            $results = [];
            
            # Iterate over the loaded content of the context.
            foreach ($this -> content as $query) {
                # Execute the iterated query.
                $this -> query($query);
                
                # Cache the result of the operation into the results array.
                $results[] = $this -> result;
            }
            
            # Cache the results array as a property.
            $this -> result = $results;
            
            # Return the context.
            return $this;
        }
        
        # The function that queries the database using an open connection.
        public function query ($query) {
            # Check whether there is an active database connection.
            if ($this -> connection && $this -> connection -> ping()) {
                # Normalise the query settings.
                $settings = isset($this -> query_settings) ? $this -> query_settings : self::$default_query_settings;

                # Create an array to store the final results and a variable to store the outcome of the operation in case it fails.
                $results = [];
                $outcome = null;

                # The arrays that will hold the values and types of the parameters.
                $values = [];
                $types = [];

                # Check whether the query has been decapsulated successfully.
                if ($filtered = self::decapsulate($query, array_merge(self::$security_tokens, ["?"]), true)) {
                    # Iterate over every extracted key-value pair.
                    foreach ($filtered["extracted"] as $key => $value) {
                        # Determine the course of action based on the value.
                        switch ($value) {
                            # Check whether the value is an integer.
                            case strval(intval($value)):
                                # Push 'i' for integer in the types array.
                                $types[] = "i";

                                # Break out of the statement.
                                break;

                            # Check whether the value is a double.
                            case strval(doubleval($value)):
                                # Push 'd' for double in the types array.
                                $types[] = "d";

                                # Break out of the statement.
                                break;

                            # In any other case consider the value a string.
                            default: $types[] = "s";
                        }

                        # Add the value by reference to values array.
                        $values[] = &$filtered["extracted"][$key];
                    }

                    # Assign the modified query to the query.
                    $query = $filtered["modified"];
                }

                # Create the 'arguments' array that will be passed to the 'bind_params' function.
                $type_string = implode("", $types);
                $arguments = array_merge([&$type_string], $values);

                # Set the charset of the connection.
                $this -> connection -> set_charset($settings["charset"]);

                # Prepare the query and check whether the operation was successful.
                if ($stmt = $this -> connection -> prepare($query)) {
                    # Check whether the typestring isn't empty.
                    if ($type_string) {
                        # Bind the parameters to the statement.
                        $stmt -> bind_param(...$arguments);
                    }

                    # Execute the statement and check whether the operation was successful.
                    if ($stmt -> execute()) {
                        # Get the result out of the statement and store it into a property.
                        $result = $this -> stmt_result = $stmt -> get_result();

                        # Close the statement.
                        $stmt -> close();

                        # Check whether the result is a boolean.
                        if (is_bool($result)) {
                            # Set the outcome mentioning the success of the operation.
                            $outcome = $settings["show_errors"] ? __METHOD__ . " → Query executed successfully!" : true;
                        }
                        else {
                            # Cache the number of rows found.
                            $rows = $result -> num_rows;
                            
                            # Select a course of action based on the number of rows found.
                            switch (true) {
                                # Check whether more than one rows were found.
                                case $rows > 1:
                                    # Iterate over every row found.
                                    while ($values = $result -> fetch_assoc()) {
                                        # Cache the value of the column directly, if only one column was fetched.
                                        $row = (count($values) === 1) ? $values[key($values)] : $values;

                                        # Insert the value in the results.
                                        $results[] = $row;
                                    }

                                    # Break out of the statement.
                                    break;

                                # Check whether one row was found.
                                case $rows === 1:
                                    # Fetch and cache the row.
                                    $row = $result -> fetch_assoc();

                                    # Cache the value of the column directly, if only one column was fetched.
                                    $row = (count($row) === 1) ? $row[key($row)] : $row;

                                    # Check whether the rows should be indexed.
                                    if ($settings["rows_indexed"]) {
                                        # Insert the value in the results.
                                        $results[] = $row;
                                    }
                                    else {
                                        # Otherwise, assign the value to the results.
                                        $results = $row;
                                    }

                                    # Break out of the statement.
                                    break;

                                # Check whether no rows were found.
                                case $rows === 0:
                                    # Set the result to an empty array or false.
                                    $results = $settings["no_rows_as_array"] ? [] : false;
                                    
                                    # Break out of the statement.
                                    break;
                            }

                            # Set the internal pointer of the result to the first entry.
                            $result -> data_seek(0);
                        }
                    }
                    else {
                        # Cache the connection error as the latest error.
                        $this -> lastError = $this -> connection -> error;

                        # Set the outcome explaining the cause of the operation's failure.
                        $outcome = $settings["show_errors"] ? __METHOD__ . " → Query execution failed due to the following error: " . $this -> lastError : false;
                    }
                }
                else {
                    # Cache the connection error as the latest error.
                    $this -> lastError = $this -> connection -> error;
                    
                    # Set the outcome explaining the cause of the operation's failure.
                    $outcome = $settings["show_errors"] ? __METHOD__ . " → Query preparation failed due to the following error: " . $this -> lastError : false;
                }
            }
            else {
                # Throw an exception.
                throw new Exception(__METHOD__. " → requires an active database connection.");
            }
            
            # Cache the result of the operation.
            $this -> result = isset($outcome) ? $outcome : $results;
            
            # Return the context.
            return $this;
        }
        
        # The function that configurates the query settings of an instance.
        public function configure ($data) {
            # Check whether the argument given is an array.
            if (is_array($data)) {
                # Check whether the function was called by the context.
                if (isset($this) && $this instanceof self) {
                    # Iterate over every key in the default settings.
                    foreach (self::$default_query_settings as $key => $value) {
                        # Check whether the iterated key exists in the given data.
                        if (isset($data[$key])) {
                            # Update the query setting with the value given.
                            $this -> query_settings[$key] = $data[$key];
                        }
                        else {
                            # Update the query setting with the default value.
                            $this -> query_settings[$key] = $value;
                        }
                    }
            
                    # Return the context.
                    return $this;
                }
                else {
                    # Iterate over every key in the data.
                    foreach ($data as $key => $value) {
                        # Check whether the key exists in the default query settings.
                        if (isset(self::$default_query_settings[$key])) {
                            # Update the default query setting with the value given.
                            self::$default_query_settings[$key] = $value;
                        }
                    }
                }
            }
            else {
                # Throw an exception.
                throw new Exception(__METHOD__ . " → The given argument must be an array.");
            }
        }

        # The function that configurates the query settings of an instance.
	public function query_config ($data) {
		# Call the 'configure' method.
		return $this -> configure($data);
	}
    }
?>
