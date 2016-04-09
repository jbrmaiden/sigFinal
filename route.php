<?php
include('config.inc.php');




   // Retrieve start point
   $start = split(' ',$_REQUEST['startpoint']);
   $startPoint = array($start[0], $start[1]);

   // Retrieve end point
   $end = split(' ',$_REQUEST['finalpoint']);
   $endPoint = array($end[0], $end[1]);

  $metodo = split(' ',$_REQUEST['method']);
  $algoritmo = $metodo[0];




$sql = "
SELECT 
  *, ST_AsGeoJSON(the_geom) as geojson, ST_Length(the_geom) as length 
FROM 
  sp_smart_directed(
    '".TABLE."', 
    true, 
    ".$startPoint[0].", 
    ".$startPoint[1].", 
    ".$endPoint[0].", 
    ".$endPoint[1].", 
    1000, 
    'cost', 
    'cost', 
    false, 
    false
  );";


   // Connect to database
   $dbcon = pg_connect("dbname=".PG_DB." host=".PG_HOST." user=".PG_USER." password=".PG_PASSWORD);
   if (!$dbcon) {
    echo "An error occurred in DB connection\n";
  }

/*Achar PONTO 1*/
$start_LineQuery = "
SELECT r.id ,ST_Distance(r.the_geom,ST_SetSRID(ST_MakePoint(".$startPoint[0].", ".$startPoint[1]."),4326)) 
FROM toronto_roads_vertices_pgr r 
ORDER BY 2 ASC LIMIT 1;";
$query = pg_query($dbcon,$start_LineQuery) or die(pg_last_error()); ; 
if (!$query) {
    echo "An error occurred in start_Line\n";
}else{
  //echo "Achamos ponto 1 \n";
  $arr = pg_fetch_array($query, 0, PGSQL_BOTH);
 // var_dump($arr);
  // echo $arr["id"] . " <- Row 1 Author\n";
  $start_Line = $arr[0];
  //echo "id: " . $start_Line . "\n";
  
}

/*Achar PONTO 2*/
$end_LineQuery = "
SELECT r.id ,ST_Distance(r.the_geom,ST_SetSRID(ST_MakePoint(".$endPoint[0].", ".$endPoint[1]."),4326)) 
FROM toronto_roads_vertices_pgr r 
ORDER BY 2 ASC LIMIT 1;";
$query = pg_query($dbcon,$end_LineQuery) or die(pg_last_error()); 
if (!$query) {
    echo "An error occurred in end_Line\n";
}else{
  //echo "Achamos ponto 2 \n";
  $arr = pg_fetch_array($query, 0, PGSQL_BOTH);
 // var_dump($arr);
  // echo $arr["id"] . " <- Row 1 Author\n";
  $end_Line = $arr[0];
  //echo "id: " . $end_Line . "\n";
  
}


if ($algoritmo == 1){
  $sql = "
   DROP VIEW IF EXISTS \"path\" CASCADE;
  CREATE VIEW \"path\" as
  SELECT * FROM   pgr_astar('SELECT id, source, target, cost, x1, y1, x2, y2 FROM toronto_roads', ".$start_Line. ", ".$end_Line.", true, false);

  DROP VIEW IF EXISTS Interm CASCADE;
  CREATE VIEW Interm AS
  select A.seq as seq, A.id1 as geomId1, B.id1 as geomId2
  FROM \"path\" A ,  \"path\" B WHERE (A.seq+1)  =  B.seq ;

  DROP TABLE IF EXISTS AstarResult;
  CREATE TABLE AstarResult as 
  SELECT B.id as id, ST_MakeLine(B.the_geom, C.the_geom) as the_geom
  FROM Interm A join toronto_roads_vertices_pgr B on A.geomId1 = B.id join toronto_roads_vertices_pgr C on A.geomId2 = C.id;  ";
 
    $runSQL = pg_query($dbcon,$sql) or die(pg_last_error());
    if (!$runSQL) {
        echo "An error occurred in creating routing table\n";
    } else{
        //echo "Criou tabela com rota \n";
        $sql2 = "
            SELECT *, ST_AsGeoJSON(the_geom) as geojson  FROM AstarResult;";
        $runSQL2 = pg_query($dbcon,$sql2) or die(pg_last_error());
        if (!$runSQL2) {
            echo "An error occurred in fetching routing table\n";
        }/*else{
            while ($row = pg_fetch_row($runSQL2)) {
              foreach($row as $i => $attr){
                echo $attr.", ";
              }
              echo ";";
            }
        }*/
        
    }
}


if ($algoritmo == 2){
  $sql = "
   DROP VIEW IF EXISTS \"path\" CASCADE;
  CREATE VIEW \"path\" as
  SELECT * FROM   pgr_dijkstra('SELECT id, source, target, cost, x1, y1, x2, y2 FROM toronto_roads', ".$start_Line. ", ".$end_Line.", true, false);

  DROP VIEW IF EXISTS Interm CASCADE;
  CREATE VIEW Interm AS
  select A.seq as seq, A.id1 as geomId1, B.id1 as geomId2
  FROM \"path\" A ,  \"path\" B WHERE (A.seq+1)  =  B.seq ;

  DROP TABLE IF EXISTS dijkstraResult;
  CREATE TABLE dijkstraResult as 
  SELECT B.id as id, ST_MakeLine(B.the_geom, C.the_geom) as the_geom
  FROM Interm A join toronto_roads_vertices_pgr B on A.geomId1 = B.id join toronto_roads_vertices_pgr C on A.geomId2 = C.id;  ";
 
    $runSQL = pg_query($dbcon,$sql) or die(pg_last_error());
    if (!$runSQL) {
        echo "An error occurred in creating routing table\n";
    } else{
        //echo "Criou tabela com rota \n";
        $sql2 = "
            SELECT *, ST_AsGeoJSON(the_geom) as geojson  FROM dijkstraResult;";
        $runSQL2 = pg_query($dbcon,$sql2) or die(pg_last_error());
        if (!$runSQL2) {
            echo "An error occurred in fetching routing table\n";
        }/*else{
            while ($row = pg_fetch_row($runSQL2)) {
              foreach($row as $i => $attr){
                echo $attr.", ";
              }
              echo ";";
            }
        }*/
        
    }
}




   // Return route as GeoJSON
   $geojson = array(
      'type'      => 'FeatureCollection',
      'features'  => []
   ); 
   // echo "Antes do while";
   // Add edges to GeoJSON array
   while($edge=pg_fetch_assoc($runSQL2) ) {  
      $feature = array(
         'type' => 'Feature',
         'geometry' => json_decode($edge['geojson'], true),
         
         'properties' => array(
            'id' => $edge['id']
         )
      );

      //echo ($feature['type']. "oi");
      // Add feature array to feature collection array
      //$geojson['features'] = $feature;
      array_push($geojson['features'], $feature);
   }

	//echo "\nDepois do loop" ;
  //var_dump( $geojson );
   // Close database connection
   pg_close($dbcon);
   //var_dump($geojson['features']);
   // Return routing result
   //header('Content-type: application/json',true);
   //var_dump($geojson);
   echo json_encode($geojson);
   

?>
