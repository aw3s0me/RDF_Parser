#!/usr/bin/php
<?php

require_once "./lib/easyrdf/lib/EasyRdf.php";

$voc_keys = array(
    "ID" => "ebucore:identifier",
    "CourseID" => "rdf:ID",
    "First Visited Date" => "ex:FirstVisitedDate" ,
    "Title" => "rdf:label",
    "License Type" => "dcterms:type", //the type of the licensed document
    "License: exists" => "dcterms:license",
    "License: open" => "cc:Sharing",
    "License: attribution" => "cc:Attribution",
    "License: share-alike" => "cc:ShareAlike",
    "License: non-commercial" => "cc:CommercialUse",
    "License: No Derivative" => "cc:DerivativeWorks",
    "License: HumanReadable" => "ex:HumanReadableLicense",
    "License: MachineReadable" => "ex:MachineReadableLicense",
    "Original Language" => "ebucore:OriginalLanguage",
    "Multilingualism: exists" => "ex:IsMultilingualismExists",
    "Second Language" => "mdc:Different-Languages",
    "Content Format PDF" => "ebucore:Format",
    "Content Format Video" => "ebucore:VideoFormat",
    "Video Closed Caption" => "ebucore:ClosedCaptions",
    "Content Format Audio" => "ebucore:AudioFormat",
    "Content Format HTML" => "ebucore:Format",
    "Content Format Others" => "ebucore:Format",
    "Re-purposing" => "mdc:Changes-to-Content",
    "Re-Purposing note" => "ex:Re-PurposingNote",
    "Repurposing Type" => "mdc:Different-Content-Types",
    "Last Published" => "ebucore:publishedEndDateTime",
    "Availability" => "dcterms:available",
    "Account needed for download" => "ex:IsAccountNeededForDownload",
    "Versioning" =>"ebucore:hasVersion",
    "Unit Type" => "org:hasUnit",
    "Unit amount" => "va:unit",
    "Direct Shareablity" => "ex:DirectShareablity",
    "Account needed for comment" => "ex:IsAccountNeededForComment",
    "Metadata availability" => "ex:IsMetadataAvailable",
    "Metadata human-readable" => "ex:IsMetadataHumanReadable",
    "Metadata machine-readable" => "ex:IsMetadataMachineReadable",
    "Schema" => "nrl:Schema",
    "Education Level" => "dcterms:educationLevel",
    "Redirectet to" => "ex:RedirectedTo",
    "Extra Symbol" => "ex:Extra"
);

// Function to convert CSV into associative array
function csv_to_array($file, $delimiter) {
    if(!ini_set('default_socket_timeout',    15))
        echo "<!-- unable to change socket timeout -->";

    if (($handle = fopen($file, 'r')) !== FALSE) {
        $i = 0;
        while (($lineArray = fgetcsv($handle, 4000, $delimiter, '"')) !== FALSE) {
            for ($j = 0; $j < count($lineArray); $j++) {
                $arr[$i][$j] = $lineArray[$j];
            }
            $i++;
        }
        fclose($handle);
    }
    else {
        die("Problem when accessing to url");
    }

    return $arr;
}

function is_empty_str($question){
    return (!isset($question) || trim($question)==='');
}

function process_spreadsheet($path) {
    $keys = array();
    $newArray = array();

    $data = csv_to_array($path, ',');

    // Set number of elements (minus 1 because we shift off the first row)
    $count = count($data) - 1;

    //Use first row for names
    $labels = array_shift($data);

    foreach ($labels as $label) {
        $keys[] = $label;
    }

    // Add Ids, just in case we want them later
    $keys[] = 'id';

    for ($i = 0; $i < $count; $i++) {
        $data[$i][] = $i;
    }

    // Bring it all together
    for ($j = 0; $j < $count; $j++) {
        $d = array_combine($keys, $data[$j]);
        if (!is_empty_str($d["ID"])) {
//            $newArray[$j] = $d;
            $newArray[$d["ID"]] = $d;
        }

    }

    return $newArray;
}

function get_uris() {
    $json_string = file_get_contents("./data/uris.txt");
    return json_decode($json_string, true);
}

function init_vocabularies() {
    $voc_prefixes = array(
        "cc" => "http://creativecommons.org/ns#",
        "mdc" => "http://purl.org/meducator/repurposing",
        "rdf" => "http://www.w3.org/1999/02/22-rdf-syntax-ns#",
        "dcterms" => "http://purl.org/dc/terms",
        "ebucore" => "http://www.ebu.ch/metadata/ontologies/ebucore/ebucore",
        "nrl" => "http://www.semanticdesktop.org/ontologies/2007/08/15/nrl",
        "va" => "http://code-research.eu/ontology/visual-analytics",
        "ex" => "http://example.org"
    );

    foreach($voc_prefixes as $key => $value) {
        EasyRdf_Namespace::set($key, $value);
    }
}

function convert_to_rdf($path) {
    //get processed array
    $uris = get_uris();
    $result = process_spreadsheet($path);
    init_vocabularies();
    global $voc_keys;

    $root = new EasyRdf_Graph();
    $courses = $root->newBNode('rdf:Seq'); //create container

    //iterate through array and create nodes
    foreach($result as $key => $value){
        $resource_uri = $uris[$key];
        $temp_cours = new EasyRdf_Resource($resource_uri, $root);

        $courses->append($temp_cours);

        foreach($value as $propName => $propValue) {
            if ($propName == null || $propName == "" || $propName == "id"){
                continue;
            }
            $predicate_url = $voc_keys[$propName];
            //add to resource predicate with property. probably addLiteral method
            $temp_cours->addLiteral($predicate_url, $propValue);
        }
    }

    return $root->serialise("rdfxml");
}

function write_res_to_file($file, $result) {
    $file = "./" . $file;
    $fh = fopen($file, (file_exists($file)) ? 'a' : 'w');
    fwrite($fh, $result."\n");
    fclose($fh);
}

function process_google_docs_url($string){
    $start = 'spreadsheets/d/';
    $end = '/edit?usp=sharing';
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    $key = substr($string, $ini, $len);
    return "https://docs.google.com/spreadsheet/pub?key=" . $key . "&single=true&gid=0&output=csv";
}

if (isset($argv[1]) && isset($argv[2])) {
    echo 'Path to web resource ' . $argv[1] . ' and output is: ' . $argv[2] . '\n';
    echo 'Processing.......... \n';
//    $spreadsheet_url="https://docs.google.com/spreadsheets/d/13OJOEBuzseaktGn3m78paKKKhRASo0wddu8gmrAj_TY/edit?usp=sharing";
    //$spreadsheet_url="https://docs.google.com/spreadsheet/pub?key=13OJOEBuzseaktGn3m78paKKKhRASo0wddu8gmrAj_TY&single=true&gid=0&output=csv";
    $spreadsheet_url = $argv[1];
    $output_filename = $argv[2];
//    $output_filename = "OUTPUT";
    $spreadsheet_url = process_google_docs_url($spreadsheet_url);
    $rdf_result = convert_to_rdf($spreadsheet_url);
    write_res_to_file($output_filename, $rdf_result);
    echo 'Ready \n';
}
else {
    echo 'Please set correctly path and output. Format is: <path> <output>';
}

?>