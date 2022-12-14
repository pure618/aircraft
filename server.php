<?php

class Server
{
    public $conn;

    public function __construct()
    {
        $host = "localhost";
        $username = "root";
        $password = "";
        $database = "aircraft";

        $this->conn = new mysqli($host, $username, $password, $database);

        if ($this->conn->connect_error) {
            die("Connection failed: " . mysqli_connect_error());
        }
    }

    public function _is_time_over($date1, $date2)
    {
        $d1 = new DateTime($date1);
        $d2 = new DateTime($date2);

        $interval = $d2->diff($d1);

        $month = $interval->format('%m');
        return $month >= 1;
    }

    public function _write_to_files()
    {
        $data = ['reg_time' => date('Y-m-d H:i:s')];
        file_put_contents('regtime.json', json_encode($data));

        $authorization = "Authorization: Bearer 7af24899d824abfefac14930baf681f40e4427fd";

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
            $result = curl_exec($ch); // Execute the cURL statement
            file_put_contents("$aircraft.json", $result);
            curl_close($ch);
        }
    }

    public function read_data()
    {
        $regtime_data = file_get_contents('regtime.json');

        if ($regtime_data === false) {
            $this->_write_to_files();
            die ('wait');
        } else {
            $data = json_decode($regtime_data, true);

            if ($this->_is_time_over($data['reg_time'], date('Y-m-d H:i:s'))) {
                $this->_write_to_files();
                die ('wait');
            }
            die ('success');
        }
    }

    public function get_aircraft_list()
    {
        $sql = "SELECT distinct icao FROM tb_aircraft";
        $fetch_result = $this->conn->query($sql);

        $aircraft_list = [];
        foreach ($fetch_result as $item) {
            $aircraft_list[] = $item['icao'];
        }

        die (json_encode($aircraft_list));
    }

    public function get_country_list()
    {
        $continent = $_GET['continent'];
        $sql = "SELECT distinct country FROM tb_airport WHERE 1=1";
        if (!empty($continent)) {
            $sql .= " and continent = '${continent}'";
        }
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
        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[] = $item['city'];
        }

        die (json_encode($result));
    }

    public function get_jet_list()
    {
        $sql = "SELECT distinct aircraft_class FROM tb_aircraft WHERE 1=1";
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
            $sql .= " and aircraft_class = '{jet}'";
        }
        if (!empty($make)) {
            $sql .= " and make = '${make}'";
        }

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
        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[$item['icao']] = $item;
        }

        die (json_encode($result));
    }

    public function get_aircraft_model_list()
    {
        $make = $_GET['make'];
        $jet = $_GET['jet'];
        $model = $_GET['model'];

        $sql = "SELECT * FROM `tb_aircraft` where 1=1";
        if (!empty($make)) {
            $sql .= " and make = '${make}'";
        }
        if (!empty($model)) {
            $sql .= " and country = '${model}'";
        }
        if (!empty($jet)) {
            $sql .= " and aircraft_class = '${jet}'";
        }
        $fetch_result = $this->conn->query($sql);

        $result = [];
        foreach ($fetch_result as $item) {
            $result[$item['api_name']] = $item;
        }

        die (json_encode($result));
    }
}

$server = new Server();

$f = $_GET['f'];
switch ($f) {
    case 'read_data':
        $server->read_data();
        break;
    case 'get_aircraft_list':
        $server->get_aircraft_list();
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
    case 'get_aircraft_model_list':
        $server->get_aircraft_model_list();
        break;
}
