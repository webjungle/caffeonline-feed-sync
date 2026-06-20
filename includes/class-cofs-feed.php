<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class COFS_Feed {
    private $url;
    public function __construct( $url ) { $this->url = $url; }
    public function get_rows() {
        $resp = wp_remote_get( $this->url, [ 'timeout' => 20, 'headers' => [ 'Accept' => 'text/csv,text/plain,*/*' ] ] );
        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );
        if ( $code !== 200 || ! $body ) return new WP_Error( 'cofs_http', sprintf( __( 'HTTP Fehler (%d) oder leerer Body.', 'caffeonline-feed-sync' ), intval($code) ) );
        return $this->parse_csv( $body );
    }
    private function parse_csv( $blob ) {
        $delims = [';', ',', "\t"];
        $lines = preg_split( '/\r\n|\r|\n/', trim( $blob ) );
        if ( empty( $lines ) ) return new WP_Error( 'cofs_csv_empty', __( 'CSV scheint leer zu sein.', 'caffeonline-feed-sync' ) );
        $header_line = preg_replace( '/^\xEF\xBB\xBF/', '', $lines[0] );
        $best = ','; $max = 0;
        foreach ( $delims as $d ) { $p = str_getcsv( $header_line, $d ); if ( count($p) > $max ) { $max = count($p); $best = $d; } }
        $headers = array_map( 'trim', str_getcsv( $header_line, $best ) );
        $rows = [];
        for ( $i=1; $i<count($lines); $i++ ) {
            if ( $lines[$i] === '' ) continue;
            $cols = str_getcsv( $lines[$i], $best );
            if ( count($cols) < count($headers) ) { $cols = array_pad( $cols, count($headers), '' ); }
            $row = [];
            foreach ( $headers as $idx => $h ) { $row[$h] = isset($cols[$idx]) ? $cols[$idx] : ''; }
            $rows[] = $row;
        }
        return $rows;
    }
    public function col( $row, $names = [] ) {
        foreach ( $names as $n ) { if ( isset($row[$n]) && $row[$n] !== '' ) return $row[$n]; }
        $lower = []; foreach ( $row as $k=>$v ) $lower[strtolower(trim((string) $k))] = $v;
        foreach ( $names as $n ) { $ln = strtolower(trim((string) $n)); if ( isset($lower[$ln]) && $lower[$ln] !== '' ) return $lower[$ln]; }
        return '';
    }
}
