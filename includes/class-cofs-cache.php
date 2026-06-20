<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class COFS_Cache {
    private $url;
    private $ttl;
    private $max_rows;
    private $hash;
    private $dir;
    private $file;
    private $meta_file;

    public function __construct( $url, $ttl_minutes = 60, $max_rows = 0 ) {
        $this->url       = $url;
        $this->ttl       = max( 1, intval( $ttl_minutes ) ) * 60;
        $this->max_rows  = max( 0, intval( $max_rows ) );

        // IMPORTANT: include max_rows in hash so changing it creates a new cache file
        $this->hash = md5( $url . '|' . $this->max_rows );

        $uploads   = wp_get_upload_dir();
        $this->dir = trailingslashit( $uploads['basedir'] ) . 'cofs';
        $this->file = $this->dir . '/cache-' . $this->hash . '.json';
        $this->meta_file = $this->file . '.meta.json';

        if ( ! file_exists( $this->dir ) ) {
            wp_mkdir_p( $this->dir );
        }
    }

    public function prepare( $force = false ) {
        if ( ! $force && file_exists( $this->file ) && ( time() - filemtime( $this->file ) ) < $this->ttl ) {
            $meta = $this->meta();
            if ( $meta ) {
                if ( isset( $meta['format'] ) && 'jsonl' === $meta['format'] ) {
                    return $meta;
                }

                $upgraded = $this->upgrade_legacy_cache();
                if ( ! is_wp_error( $upgraded ) && $upgraded ) {
                    return $upgraded;
                }

                return $meta;
            }
        }

        $feed = new COFS_Feed( $this->url );
        $rows = $feed->get_rows();
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        if ( $this->max_rows > 0 ) {
            $rows = array_slice( $rows, 0, $this->max_rows );
        }

        return $this->write_rows( array_values( $rows ) );
    }

    // Public, da von mehreren Plugin-Komponenten genutzt.
    public function meta() {
        if ( ! file_exists( $this->file ) ) {
            return false;
        }

        $stored = $this->read_meta_file();
        if ( $stored ) {
            return [
                'hash'     => $this->hash,
                'path'     => $this->file,
                'size'     => filesize( $this->file ),
                'total'    => isset( $stored['total'] ) ? (int) $stored['total'] : 0,
                'max_rows' => $this->max_rows,
                'format'   => isset( $stored['format'] ) ? (string) $stored['format'] : 'jsonl',
            ];
        }

        return [
            'hash'     => $this->hash,
            'path'     => $this->file,
            'size'     => filesize( $this->file ),
            'total'    => $this->count_rows(),
            'max_rows' => $this->max_rows,
            'format'   => $this->detect_format(),
        ];
    }

    private function count_rows() {
        if ( 'jsonl' === $this->detect_format() ) {
            return $this->count_jsonl_rows();
        }

        $buf = file_get_contents( $this->file );
        $arr = json_decode( $buf, true );
        return is_array( $arr ) ? count( $arr ) : 0;
    }

    public function read_slice( $offset, $limit ) {
        if ( ! file_exists( $this->file ) ) {
            return new WP_Error( 'cofs_cache_missing', __( 'Kein vorbereiteter Cache gefunden. Bitte „Feed vorbereiten“.', 'caffeonline-feed-sync' ) );
        }

        if ( 'jsonl' === $this->detect_format() ) {
            return $this->read_jsonl_slice( $offset, $limit );
        }

        $arr = json_decode( file_get_contents( $this->file ), true );
        if ( ! is_array( $arr ) ) {
            return new WP_Error( 'cofs_cache_json', __( 'Ungültiger Cache-Inhalt.', 'caffeonline-feed-sync' ) );
        }

        $total = count( $arr );
        $rows  = array_slice( $arr, $offset, $limit );

        return [
            'rows'        => $rows,
            'total'       => $total,
            'next_offset' => min( $total, $offset + $limit ),
        ];
    }

    private function write_rows( array $rows ) {
        $tmp = $this->file . '.tmp-' . uniqid( '', true );
        $fh  = fopen( $tmp, 'wb' );

        if ( ! $fh ) {
            return new WP_Error( 'cofs_cache_write', __( 'Konnte Cache-Datei nicht schreiben.', 'caffeonline-feed-sync' ) );
        }

        $total   = 0;
        $offsets = [];
        foreach ( $rows as $row ) {
            $line = wp_json_encode( $row );
            $offsets[] = ftell( $fh );
            if ( false === $line || false === fwrite( $fh, $line . "\n" ) ) {
                fclose( $fh );
                @unlink( $tmp );
                return new WP_Error( 'cofs_cache_write', __( 'Konnte Cache-Datei nicht schreiben.', 'caffeonline-feed-sync' ) );
            }
            $total++;
        }

        fclose( $fh );

        if ( ! @rename( $tmp, $this->file ) ) {
            @unlink( $tmp );
            return new WP_Error( 'cofs_cache_write', __( 'Konnte Cache-Datei nicht schreiben.', 'caffeonline-feed-sync' ) );
        }

        $meta = [
            'hash'       => $this->hash,
            'total'      => $total,
            'max_rows'   => $this->max_rows,
            'format'     => 'jsonl',
            'offsets'    => $offsets,
            'created_at' => time(),
        ];

        file_put_contents( $this->meta_file, wp_json_encode( $meta ) );

        return $this->meta();
    }

    private function read_jsonl_slice( $offset, $limit ) {
        $offset = max( 0, (int) $offset );
        $limit  = max( 1, (int) $limit );
        $total  = $this->count_rows();
        $rows   = [];
        $fh     = fopen( $this->file, 'rb' );

        if ( ! $fh ) {
            return new WP_Error( 'cofs_cache_read', __( 'Konnte Cache-Datei nicht lesen.', 'caffeonline-feed-sync' ) );
        }

        $stored  = $this->read_meta_file();
        $offsets = ( $stored && isset( $stored['offsets'] ) && is_array( $stored['offsets'] ) )
            ? $stored['offsets']
            : [];
        $line_no = 0;

        if ( isset( $offsets[ $offset ] ) ) {
            fseek( $fh, max( 0, (int) $offsets[ $offset ] ) );
            $line_no = $offset;
        }

        while ( ! feof( $fh ) ) {
            $line = fgets( $fh );
            if ( false === $line ) {
                break;
            }

            if ( $line_no++ < $offset ) {
                continue;
            }

            $line = trim( $line );
            if ( '' !== $line ) {
                $row = json_decode( $line, true );
                if ( is_array( $row ) ) {
                    $rows[] = $row;
                }
            }

            if ( count( $rows ) >= $limit ) {
                break;
            }
        }

        fclose( $fh );

        return [
            'rows'        => $rows,
            'total'       => $total,
            'next_offset' => min( $total, $offset + $limit ),
        ];
    }

    private function read_meta_file() {
        if ( ! file_exists( $this->meta_file ) ) {
            return false;
        }

        $meta = json_decode( file_get_contents( $this->meta_file ), true );
        if ( ! is_array( $meta ) || ( $meta['hash'] ?? '' ) !== $this->hash ) {
            return false;
        }

        return $meta;
    }

    private function detect_format() : string {
        $stored = $this->read_meta_file();
        if ( $stored && isset( $stored['format'] ) ) {
            return (string) $stored['format'];
        }

        $fh = fopen( $this->file, 'rb' );
        if ( ! $fh ) {
            return 'legacy_json';
        }

        $first = '';
        while ( ! feof( $fh ) ) {
            $char = fgetc( $fh );
            if ( false === $char ) {
                break;
            }
            if ( trim( $char ) !== '' ) {
                $first = $char;
                break;
            }
        }
        fclose( $fh );

        return '[' === $first ? 'legacy_json' : 'jsonl';
    }

    private function count_jsonl_rows() : int {
        $stored = $this->read_meta_file();
        if ( $stored && isset( $stored['total'] ) ) {
            return max( 0, (int) $stored['total'] );
        }

        $count = 0;
        $fh    = fopen( $this->file, 'rb' );
        if ( ! $fh ) {
            return 0;
        }

        while ( ! feof( $fh ) ) {
            $line = fgets( $fh );
            if ( false !== $line && trim( $line ) !== '' ) {
                $count++;
            }
        }

        fclose( $fh );
        return $count;
    }

    private function upgrade_legacy_cache() {
        $buf  = file_get_contents( $this->file );
        $rows = json_decode( $buf, true );

        if ( ! is_array( $rows ) ) {
            return false;
        }

        return $this->write_rows( array_values( $rows ) );
    }
}
