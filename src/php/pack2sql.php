<?php
/**
 * Interpreter for datapackage (of FrictionLessData.io standards) and script (SH and SQL) generator.
 * Generate scripts at ./cache.  Need to edit the conf.json.
 *
 * USE: php src/php/pack2sql.php
 * REUSING generated scripts:  sh src/cache/make.sh
 */


$here = dirname(__FILE__); // ./src/php
$STEP = 4;
$DROP_allTmp = true; // true when usede before here the DROP cascade the SERVER tmp_*
$DROP_allDaset = true; // true when usede before here the DROP SCHEMA dataset CASCADE.
$LOCALprefix = ''; //'local_'  // for local datasets, prefix as namespace
// CONFIGS at the project's conf.json
$conf = json_decode(file_get_contents($here.'/../../conf.json'),true);

$lists = [];
foreach(['github.com','local','local-csv'] as $c)
   if (isset($conf[$c]))
      $lists[] = $c;
if (!count($lists))
   die("\nERROR: no conf needs 'github.com', 'local-csv' or 'local'.\n");
$DB = isset($conf['db'])? trim($conf['db']): '';
if (!$DB) die("\nSEM DB!\n");
$PSQL = "psql \"$DB\"";
$useIDX    = $conf['useIDX'];    // false is real name, true is tmpcsv1, tmpcsv2, etc.
$useRename = $conf['useRename']; // rename "ugly col. names" to ugly_col_names
$useYUml    = $conf['useYUml'];  // true to generate a .ymul file for diagrams.

// INITS:
$msg1 = "Script generated by datapackage.json files and pack2sql generator.";
$msg2 = "Created in ".substr(date("c", time()),0,10);
$IDX = 0;

$dropall = $DROP_allTmp? "\n	DROP SERVER IF EXISTS csv_files CASCADE;": '';
$scriptSQL = "\n--\n-- $msg1\n-- $msg2\n--\n
	CREATE EXTENSION IF NOT EXISTS file_fdw;$dropall
	CREATE SERVER csv_files FOREIGN DATA WRAPPER file_fdw;
";
$scriptSH0 = "\n##\n## $msg1\n## $msg2\n##\n";
$scriptSH  = "$scriptSH0\n	mkdir -p /tmp/tmpcsv \n";
$scriptSH_end   = '';
$scriptYUml =  $useYUml? "// $msg1\n// $msg2\n": '';

fwrite(STDERR, "\n-------------\nBEGIN of cache-scripts generation");

// //
// check local:
$localCsvConf = NULL;
$pack_r = NULL;
if (isset($conf['local-csv'])) {
  $list2 = [];
  foreach ($conf['local-csv'] as $localName=>$c) {  // expand defaults:
    //parsing:
    $c_type = is_array($c)? (has_string_keys($c)? 'a':'i'): 's';
    $nc = ($c_type=='a')? $c: [];
    if (!isset($nc['separator'])) $n['separator']=',';
    if ( $c_type=='i' )   $nc['list']=$c;
    elseif ($c_type=='s') { $c=['folder'=>$c]; $c_type='a'; }
    if ( $c_type=='a' && isset($c['folder']) )
      $nc['list'] = glob("$c[folder]/*.csv"); // take all files
    elseif (!isset($nc['list'])) die("\n check conf.json local-csv, with no list no default\n");
    $localCsvConf = $nc;
    $localPacks = ['resources'=>[]];
    foreach ($nc['list'] as $i=>$f) {
      $fname = basename($f);
      fwrite(STDERR,"\n ... Preparing local-csv $fname");
      $r = [
        'name'=>$fname, 'note'=> "is a local-csv automated pack",
        'path'=> $f,    'mediatype'=>"text/csv"
      ];
      $r['schema'] = [];
      $r['schema']['fields'] =  exe_csvstat_type($f,$nc['separator']);
      $r['_tmp_separator'] = $nc['separator'];
      $localPacks['resources'][] = $r;
      $list2["$localName/$i"] = $f; // as $prj=>$file
    } //end for
  } // for-prj
  $conf['local-csv']=$list2;
} // if isset

// // //
// MAIN:
foreach($lists as $listname) {
  fwrite(STDERR, "\n\n CONFIGS ($listname): useIDX=$useIDX, count=".count($conf[$listname])." items.\n");
  foreach($conf[$listname] as $prj=>$file) {
    if (is_array($file)) {$file = $file['folder'];} // need more settings?
    $path = '';
    fwrite(STDERR, "\n Creating cache-scripts for $prj of $listname:");
    $test = [];
    if ($listname=='local-csv') {
      $uri = $uriBase = $uriBase2 = '';
      $pack = $localPacks;
    } else {
      $uriBase = ($listname=='local')? $prj: "https://raw.githubusercontent.com/$prj";
      $uriBase2 = "$uriBase/";
      $uri = ($listname=='local')? "$uriBase/datapackage.json": "$uriBase/master/datapackage.json";
      $pack = json_decode( file_get_contents($uri), true );
    }
    foreach ($pack['resources'] as $r) if (!$file || $r['name']==$file || $r['path']==$file) {
      $IDX++;
      $sep='';
      if (isset($r['_tmp_separator'])){
        $sep = $r['_tmp_separator'];
        unset($r['_tmp_separator']);
      }
      $path = $r['path'];
      fwrite(STDERR, "\n\t Building table$IDX with $path."); //  \n\t exp. path = $uri");
      list($file2,$sql,$yuml) = addSQL($r,$IDX,$sep);
      $scriptSQL .= $sql;
      if ($listname=='github.com') {
       $url = "$uriBase/master/$path";
       $scriptSH  .= "\nwget -O $file2 -c $url";
      } else
       $scriptSH .=  "\ncp $uriBase2$path $file2";
	 } else // for-if
		 $test[] = $r['name'];
   if ($useYUml) $scriptYUml .= "\n\n[$r[name]|$yuml]";
   // use SQL dataset.export_yUML_boxes(filename)
  	if (!$path)
  		 fwrite(STDERR, "\n\t ERROR, no name corresponding to \n\t CMP '$file' != '$r[name]': \n\t".join(", ",$test)."\n");
  } // end-for-conf
} // end-for-lists

// Saving files:
$cacheFolder = "$here/../cache";  // realpath()
if (! file_exists($cacheFolder)) mkdir($cacheFolder);
$f = "$cacheFolder/step$STEP-buildDatasets.sql";
$scriptSH .= "
  $PSQL < $here/../step1-lib.sql
  $PSQL < $here/../step2-strut.sql
  $PSQL < $here/../step3-framework.sql
  $PSQL < $f
  $scriptSH_end
  $PSQL -c \"SELECT * FROM dataset.vmeta_summary\"
"; // use array steps as config
file_put_contents($f, $scriptSQL);
file_put_contents("$cacheFolder/make.sh", $scriptSH);
if ($useYUml) file_put_contents("$cacheFolder/ref_diagrams.yuml", $scriptYUml);

fwrite(STDERR, "\nEND of cache-scripts generation\n See makeTmp.* scripts at $cacheFolder\n");
fwrite(STDERR, "\n Make all by command 'sh src/cache/make.sh'\n");
fwrite(STDERR, "\n Try after, to play SQL-schema dataset, 'psql \"$conf[db]\"'\n");

// // //
// LIB

function has_string_keys(array $array) { // https://stackoverflow.com/a/4254008
  return count(array_filter(array_keys($array), 'is_string')) > 0;
}

/**
 * (can be changed to direct PHP library use)
 * Get field-names and datatypes from CSVkit's  csvstat, as shell command.
 * @param $f string the path+filename
 * @param $d CSV delimiter when not ','
 * @return array of arrays in the form [id,fieldName,fieldType]
 */
function exe_csvstat_type($f,$d=",",$assoc=true) {
  $r = [];
  exec("csvstat -d \"$d\" --type $f", $lines);  //   1. p: Text
  foreach($lines as $l) if (preg_match('/^\s*(\d+)\.\s+(.+):\s(.+)$/su',$l,$m)) {
    array_shift($m);
    $m[2] = strtolower($m[2]);
    if ($m[2]=='text') $m[2]='string';
    $r[] = $assoc? ['id'=>$m[0], 'name'=>$m[1], 'type'=>$m[2]] : $m;
  } // end for if
  return $r;
}

function pg_varname($s,$toAsc=true) {
  if ($toAsc) //  universal variable-name:
    return strtolower( preg_replace('#[^\w0-9]+#s', '_', iconv('utf-8','ascii//TRANSLIT',$s)) );
  else //  reasonable column name:
    return mb_strtolower( preg_replace('#[^\p{L}0-9\-]+#su', '_', $s), 'UTF-8' );
}


/**
 * Generates script based on FOREIGN TABLE, works fine with big-data CSV.
 */
function addSQL($r,$idx,$sep='',$useConfs=true,$useAll=true,$useView=true) {
	global $useIDX;
  global $DROP_allTmp;
  global $DROP_allDaset;

	$p = $useIDX? "tmpcsc$idx": pg_varname( preg_replace('#^.+/|\.\w+$#','',$r['path']) );
	$table = $useIDX? $p: "tmpcsv_$p";
	$file = "/tmp/tmpcsv/$p.csv";

	$fields = [];
  $yfields = [];
  $f2 = [];
  $f3 = [];
	$i=0;
	$fname_to_idx = []; // for keys only, not use aux_name

	$usePk =false;
	$pk_order = 'id';
	$pk_cols = [];
	$pk_cols1 = [];
	$pk_names = [];

	$jsoninfo = pg_escape_string( json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) );
	$sql = '';
	if ($useConfs) {
    $sql .= "\n\tINSERT INTO dataset.meta(name,info) VALUES ('$p','$jsoninfo'::JSONb);";
    $sql .= "\n\tSELECT dataset.create('$p', '$file', true, '$sep');";
  }
	return [$file, "\n\n-- -- -- $p -- -- --\n$sql", join(';',$yfields)];
}
