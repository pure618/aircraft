<?php

class Server
{
    public $conn;

    public function __construct()
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        $host = "localhost";
        $username = "root";
        $password = "";
        $database = "aircraft";

        $this->conn = new mysqli($host, $username, $password, $database);

        if ($this->conn->connect_error) {
            die("Connection failed: " . mysqli_connect_error());
        }
    }

    public function __destruct()
    {
        // TODO: Implement __destruct() method.
        $this->conn->close();
    }

    public function write_to_server()
    {
        $authorization = "Authorization: Bearer 7af24899d824abfefac14930baf681f40e4427fd";

        $this->conn->begin_transaction();

        try {

            $sql = "delete from tb_data";
            $this->conn->query($sql);

            $sql = "SELECT distinct icao FROM tb_aircraft";
            $fetch_result = $this->conn->query($sql);

            $aircraft_list = [];
            foreach ($fetch_result as $item) {
                $aircraft_list[] = $item['icao'];
            }

            foreach ($aircraft_list as $aircraft) {
                $ch = curl_init("https://api.radarbox.com/v2/aircraft/search?aircraftType=${aircraft}");
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $authorization));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $json = curl_exec($ch); // Execute the cURL statement

                $result = json_decode($json, true);
                $this->_insert_api_result_to_db($result);
                curl_close($ch);
            }
            $this->conn->commit();
        } catch (mysqli_sql_exception $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    public function get_table_data()
    {
        require_once "SSP.php";
        // Build the SQL query string from the request
        $limit = SSP::limit($_REQUEST);
        $columns = ["airport_name", "icao", "city", "country", "sum_movements", "most_popular"];
        $columnIndex = $_REQUEST["order"][0]["column"];
        $direction = $_REQUEST["order"][0]["dir"];
        $orderby = "order by ${columns[$columnIndex]} $direction";

        $where = "";
        $airport = $_POST['airport'];
        $continent = $_POST['continent'];
        $country = $_POST['country'];
        $city = $_POST['city'];
        $jet = $_POST['jet'];
        $make = $_POST['make'];
        $model = $_POST['model'];
        $start_dt = $_POST['start_dt'];
        $end_dt = $_POST['end_dt'];

        if (!empty($airport)) {
            $where .= " and airport_name like '%$airport%'";
        }
        if (!empty($continent)) {
            $where .= " and continent = '$continent'";
        }
        if (!empty($country)) {
            $where .= " and country = '$country'";
        }
        if (!empty($city)) {
            $where .= " and city = '$city'";
        }
        if (!empty($jet)) {
            $where .= " and jet = '$jet'";
        }
        if (!empty($make)) {
            $where .= " and make = '$make'";
        }
        if (!empty($model)) {
            $where .= " and model = '$model'";
        }
        if (!empty($start_dt)) {
            $where .= " and date >= '$start_dt'";
        }
        if (!empty($end_dt)) {
            $where .= " and date <= '$end_dt'";
        }

        // Main query to actually get the data
        $sql = <<<EOT
            SELECT
                A.airport_name,
                A.airport_icao,
                A.city,
                A.country,
                A.sum_movements,
                B.aircraft as most_popular
            FROM
                (
                SELECT
                    airport_name,
                    airport_icao,
                    city,
                    country,
                    max( movements ) AS max_movements,
                    sum( movements ) AS sum_movements 
                FROM
                    tb_data 
                where 1=1 $where
                GROUP BY
                    airport_icao 
                $orderby
                $limit 
                ) AS A
                LEFT JOIN ( SELECT aircraft, movements, airport_icao FROM tb_data where 1=1) AS B
                ON A.max_movements = B.movements AND A.airport_icao = B.airport_icao
EOT;

        $list = $this->conn->query($sql)->fetch_all(MYSQLI_ASSOC);

        $result = [];
        foreach ($list as $item) {
            $tmp = [];
            $idx = 0;
            $tmp[$idx++] = $item['airport_name'];
            $tmp[$idx++] = $item['airport_icao'];
            $tmp[$idx++] = $item['city'];
            $tmp[$idx++] = $item['country'];
            $tmp[$idx++] = number_format($item['sum_movements']);
            $tmp[$idx++] = $item['most_popular'];
            $result[] = $tmp;
        }

        $length_sql = <<<EOT
            SELECT count(distinct airport_icao) as cnt
            FROM
                tb_data
            WHERE 1=1
                $where
EOT;

        $max_sql = <<<EOT
            SELECT max(movements) as max_intensity
            FROM
                tb_data
            WHERE 1=1
                $where
EOT;

        // Total data set length
        $recordsTotal = ($this->conn->query($length_sql)->fetch_assoc())["cnt"];
        $maxIntensity = ($this->conn->query($max_sql)->fetch_assoc())["max_intensity"];


        //Total movements(year)
        $data = SSP::output($_REQUEST, $result, $recordsTotal, $recordsTotal);

        $year_movements_sql = <<<EOT
            select sum(movements) as movements
            from tb_data
            WHERE `date` like concat(year(now()), '%')
            $where
EOT;


        //Total movements(last month)
        $month_movements_sql = <<<EOT
            select sum(movements) as movements
            from tb_data as A
            WHERE 1=1
            $where
            group by A.`date`
            order by A.`date` desc
            limit 1
EOT;

        //Map Data
        $map_data_sql = <<<EOT
            select
                sum(movements) as movements,
                lat,
                lng
            from tb_data
            WHERE 1=1 $where
            group by airport_icao
EOT;

        $graph_data_sql = <<<EOT
            select
                `date`,
                sum(movements) as movements
            from tb_data
            WHERE 1=1
            $where
            group by `date`
EOT;

        $tmp_graph_data = $this->conn->query($graph_data_sql);
        $graph_data = [];
        foreach ($tmp_graph_data as $item) {
            $tmp = explode("-", $item["date"]);
            $year = $tmp[0];
            $month = intval($tmp[1]);
            $graph_data[$year][$month] = $item['movements'];
        }


        $data["yearMovements"] = intval(($this->conn->query($year_movements_sql)->fetch_assoc())["movements"] ?? 0);
        $data["monthMovements"] = intval(($this->conn->query($month_movements_sql)->fetch_assoc())["movements"] ?? 0);
        $data["mapData"] = $this->conn->query($map_data_sql)->fetch_all(MYSQLI_ASSOC);
        $data['graphData'] = $graph_data;
        $data['maxIntensity'] = $maxIntensity;
        /*
         * Output
         */
        echo json_encode($data);
    }

    public function get_country_list()
    {
        $continent = $_GET['continent'];
        $sql = "SELECT distinct country FROM tb_airport WHERE 1=1";
        if (!empty($continent)) {
            $sql .= " and continent = '${continent}'";
        }
        $sql .= " order by country";
        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[] = $item['country'];
        }

        die (json_encode($result));
    }

    public function get_city_list()
    {
        $continent = $_GET['continent'];
        $country = $_GET['country'];
        $sql = "SELECT distinct city FROM tb_airport WHERE 1=1";
        if (!empty($continent)) {
            $sql .= " and continent = '${continent}'";
        }
        if (!empty($country)) {
            $sql .= " and country = '${country}'";
        }
        $sql .= " order by city";
        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[] = $item['city'];
        }

        die (json_encode($result));
    }

    public function get_jet_list()
    {
        $sql = "SELECT distinct aircraft_class FROM tb_aircraft WHERE 1=1 order by aircraft_class";
        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[] = $item['aircraft_class'];
        }

        die (json_encode($result));
    }

    public function get_make_list()
    {
        $jet = $_GET['jet'];
        $sql = "SELECT distinct make FROM tb_aircraft WHERE 1=1";
        if (!empty($jet)) {
            $sql .= " and aircraft_class = '${jet}'";
        }
        $sql .= " order by make";

        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[] = $item['make'];
        }

        die (json_encode($result));
    }

    public function get_model_list()
    {
        $jet = $_GET['jet'];
        $make = $_GET['make'];
        $sql = "SELECT distinct model FROM tb_aircraft WHERE 1=1";
        if (!empty($jet)) {
            $sql .= " and aircraft_class = '${jet}'";
        }
        if (!empty($make)) {
            $sql .= " and make = '${make}'";
        }
        $sql .= " order by model";

        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[] = $item['model'];
        }

        die (json_encode($result));
    }

    public function get_airport_list()
    {
        $continent = $_GET['continent'];
        $country = $_GET['country'];
        $city = $_GET['city'];
        $airport = $_GET['airport'];
        $sql = "SELECT * FROM `tb_airport` where 1=1";
        if (!empty($continent)) {
            $sql .= " and continent = '${continent}'";
        }
        if (!empty($country)) {
            $sql .= " and country = '${country}'";
        }
        if (!empty($city)) {
            $sql .= " and city = '${city}'";
        }
        if (!empty($airport)) {
            $sql .= " and airport_name like '%${airport}%'";
        }
        $sql .= " order by airport_name";
        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[$item['icao']] = $item;
        }

        die (json_encode($result));
    }

    public function json_to_db()
    {
        $sql = "SELECT distinct icao FROM tb_aircraft";
        $fetch_result = $this->conn->query($sql);

        $aircraft_list = [];
        foreach ($fetch_result as $item) {
            $aircraft_list[] = $item['icao'];
        }

        $this->conn->begin_transaction();

        try {

            foreach ($aircraft_list as $aircraft) {
                if (!file_exists("$aircraft.json")) {
                    continue;
                }
                $json = file_get_contents("$aircraft.json");
                $result = json_decode($json, true);
                if (!array_key_exists("aircraft", $result)) {
                    continue;
                }
                $this->_insert_api_result_to_db($result);
            }
            $this->conn->commit();
            die ("success");
        } catch (mysqli_sql_exception $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    public function _insert_api_result_to_db($api_result)
    {
        $api_result_list = $api_result["aircraft"];

        $airport_list = [];
        $aircraft_list = [];

        $sql = "select * from tb_aircraft";
        $aircraft_result = $this->conn->query($sql);


        foreach ($aircraft_result as $item) {
            $aircraft_list[$item["icao"] . "_" . $item["api_name"]] = $item;
        }

        $sql = "select * from tb_airport";
        $airport_result = $this->conn->query($sql);
        foreach ($airport_result as $item) {
            $airport_list[$item["icao"]] = $item;
        }

        $result = [];

        foreach ($api_result_list as $aircraft) {
            $aircraft_icao = $aircraft["typeIcao"];
            $type_description = $aircraft["typeDescription"] ?? "";

            if (!empty($aircraft["aircraftStatitics"])) {
                foreach ($aircraft["aircraftStatitics"] as $statitics) {
                    $year = $statitics["year"];
                    $month = $statitics["month"];
                    $date = $year . "-" . ($month >= 10 ? $month : "0" . $month);
                    if (!empty($statitics["airportMovements"])) {
                        foreach ($statitics["airportMovements"] as $airportMovement) {
                            $airport_icao = $airportMovement["icaoCode"];
                            $movements = $airportMovement["movements"];
                            $idx = $aircraft_icao . "_" . $type_description . "_" . $airport_icao . "_" . $date;
                            $result[$idx] = ($result[$idx] ?? 0) + $movements;
                        }
                    }
                }
            }
        }

        foreach (array_keys($result) as $key) {
            $tmp = explode("_", $key);
            $aircraft_icao = $tmp[0];
            $type_description = $tmp[1];
            $airport_icao = $tmp[2];
            $date = $tmp[3];
            $movements = $result[$key];

            $aircraft = $aircraft_list[$aircraft_icao . "_" . $type_description] ?? "";
            $airport = $airport_list[$airport_icao] ?? "";

            if (!empty($aircraft) && !empty($airport)) {

                $sql = <<<EOT
                    insert into tb_data
                        (jet, make, model, aircraft, `date`, airport_icao, airport_name, continent, country, city, lat, lng, movements)
                    values
                    (
                        "${aircraft["aircraft_class"]}",
                        "${aircraft["make"]}",
                        "${aircraft["model"]}",
                        "${aircraft["aircraft"]}",
                        "${date}",
                        "${airport["icao"]}",
                        "${airport["airport_name"]}",
                        "${airport["continent"]}",
                        "${airport["country"]}",
                        "${airport["city"]}",
                        ${airport["lat"]},
                        ${airport["lng"]},
                        ${movements}
                    )
EOT;

                if (!$this->conn->query($sql)) {
                    echo "Error: " . $sql . "<br>" . $this->conn->error;
                    exit;
                }
            }
        }
    }
}

$server = new Server();

$f = $_GET['f'];
switch ($f) {
    case 'write_to_server':
        $server->write_to_server();
        break;
    case 'get_table_data':
        $server->get_table_data();
        break;
    case 'get_country_list':
        $server->get_country_list();
        break;
    case 'get_city_list':
        $server->get_city_list();
        break;
    case 'get_airport_list':
        $server->get_airport_list();
        break;
    case 'get_make_list':
        $server->get_make_list();
        break;
    case 'get_model_list':
        $server->get_model_list();
        break;
    case 'get_jet_list':
        $server->get_jet_list();
        break;
    case 'json_to_db':
        $server->json_to_db();
        break;
}
