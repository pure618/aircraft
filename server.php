<?php

class Server
{
    public $aircraft_list;

    public function __construct()
    {
        $this->aircraft_list = $this->_get_aircraft_list();
    }

    public function _is_time_over($date1, $date2)
    {
        $d1 = new DateTime($date1);
        $d2 = new DateTime($date2);

        $interval = $d2->diff($d1);

        $month = $interval->format('%m');
        return $month >= 1;
    }

    public function _get_aircraft_list()
    {
        $aircraft_list = file_get_contents('aircraft_type.json');
        return json_decode($aircraft_list, true);
    }

    public function _write_to_files()
    {
        $data = ['reg_time' => date('Y-m-d H:i:s')];
        file_put_contents('regtime.json', json_encode($data));

        $authorization = "Authorization: Bearer 7af24899d824abfefac14930baf681f40e4427fd";
        foreach ($this->aircraft_list as $aircraft) {
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
}

$server = new Server();

$f = $_GET['f'];
if ($f == 'read_data') {
    $server->read_data();
}
