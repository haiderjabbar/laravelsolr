<?php

namespace HaiderJabbar\LaravelSolr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
class LaravelSolr
{
    protected $solrUrl;
    protected $solrUsername;
    protected $solrPassword;

    public function __construct()
    {
        $this->solrUrl = config('solr.url');
        $this->solrUsername = config("solr.solr_username");
        $this->solrPassword = config("solr.solr_password");
    }

    public function query($params)
    {
        // Example of how you might interact with Solr
        $url = $this->buildUrl('select', $params);

        // Perform the request and return the results
        $response = Http::get($url);
        return response()->json($response);
    }

    /**
     * Build a URL for Solr API requests.
     *
     * @param string $endpoint
     * @param array $params
     * @return string
     */
    private function buildUrl(string $endpoint, array $params): string
    {
        if (!empty($this->solrUsername) && !empty($this->solrPassword)) {
            $url = $this->solrUrl . '/' . $endpoint . '?' . http_build_query($params);
            $url = Str::of($url)->replace('://', '://' . $this->solrUsername . ':' . $this->solrPassword . '@', $url);
        } else {
            $url = $this->solrUrl . '/' . $endpoint . '?' . http_build_query($params);
        }
        return $url;
    }
}
