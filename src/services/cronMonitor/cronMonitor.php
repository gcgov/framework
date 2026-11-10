<?php
namespace gcgov\framework\services\cronMonitor;


use gcgov\framework\config;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Records the start and end of a long-running cron/CLI task against the cron monitor
 * web service configured at `cronMonitor.url`.
 *
 * Not a Framework Service: it contributes no routes and takes no part in the request
 * lifecycle, so there is nothing to enable. Construct it and call end().
 *
 * Deliberately fail-safe — every HTTP and JSON error is swallowed, because monitoring
 * must never break the job it monitors. The cost is that a misconfigured url fails
 * silently; check `cronMonitor.url` if runs are not appearing in the monitor.
 */
class cronMonitor {

	private string                                $jobId;
	private ?\GuzzleHttp\Client                   $client     = null;
	private ?\GuzzleHttp\Promise\PromiseInterface $jobPromise = null;

	public function __construct( string $jobId ) {
		$this->jobId = $jobId;

		// An empty url disables reporting, as the config docblock and CLAUDE.md §8 both
		// say. Without this guard the documented off switch did nothing: the constructor
		// built a client with an empty base_uri and fired a request at a hostless relative
		// URI, and because the rejection only surfaces in end()'s wait(), every cron run on
		// a default-configured application blocked on one doomed request and then issued a
		// second. The constructor is also the one place in this fail-safe class with no
		// try/catch, so its contract only held from the second line on.
		if( !config::getCronMonitor()->isConfigured() ) {
			return;
		}

		$this->client = new \GuzzleHttp\Client( [ 'base_uri' => config::getCronMonitor()->url ] );
		// Fired asynchronously so the start ping overlaps the job rather than delaying it.
		$this->jobPromise = $this->client->requestAsync( 'GET', 'jobHistory/start/' . $this->jobId );
	}

	public function end(): void {
		if( $this->client===null || $this->jobPromise===null ) {
			return;
		}

		$runId = '';
		//make sure the job started and get the run id from it's response
		try {
			$response       = $this->jobPromise->wait();
			$parsedResponse = json_decode( (string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR );
			if( is_object( $parsedResponse ) && isset( $parsedResponse->data ) ) {
				$runId = (string) $parsedResponse->data;
			}
		}
		catch( \JsonException|\Exception $e ) {
		}

		//end the job regardless of whether we got a successful start or not
		try {
			$this->client->request( 'GET', 'jobHistory/end/'.$this->jobId.'/' . $runId );
		}
		catch( \Exception|GuzzleException $e ) {
		}
	}

}
