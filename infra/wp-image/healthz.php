<?php
/**
 * Liveness endpoint for Container Apps probes.
 *
 * Deliberately does not bootstrap WordPress or touch the database. A probe that
 * depends on MySQL will restart the container during a database blip, which
 * turns a recoverable incident into a crash loop.
 */
header( 'Content-Type: text/plain' );
header( 'Cache-Control: no-store' );
echo "ok\n";
