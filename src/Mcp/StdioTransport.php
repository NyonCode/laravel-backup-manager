<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp;

/**
 * Runs an MCP {@see Server} over newline-delimited JSON on stdin/stdout.
 *
 * The streams are injected so the whole loop can be driven from a test with
 * in-memory streams. Nothing else may write to the output stream: an MCP client
 * parses every line it receives, so a stray `echo` breaks the session — which is
 * why the console command routes its own output to stderr.
 */
final class StdioTransport
{
    public function __construct(private readonly Server $server) {}

    /**
     * @param  resource  $input
     * @param  resource  $output
     */
    public function run($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            $reply = $this->server->handle($line);

            if ($reply === null) {
                continue;
            }

            fwrite($output, $reply."\n");
            fflush($output);
        }
    }
}
