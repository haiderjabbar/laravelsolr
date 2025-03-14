<?php

namespace HaiderJabbar\LaravelSolr\Services;

use Symfony\Component\Console\Output\ConsoleOutput;
use Exception;
use Illuminate\Support\Str;

class CoreSolrService
{
    /** @var string */
    private $solrUrl;

    /** @var string */
    private $solrUsername;

    /** @var string */
    private $solrPassword;

    /** @var ConsoleOutput */
    public $output;

    /**
     * CoreSolrService constructor.
     */
    public function __construct()
    {
        $this->output = new ConsoleOutput();
        $this->solrUrl = config('solr.solr_url');
        $this->solrUsername = config("solr.solr_username");
        $this->solrPassword = config("solr.solr_password");

        if (empty($this->solrUrl)) {
            throw new Exception("Solr URL is not set in the environment variables.");
        }
    }

    /**
     * Create a Solr core if it doesn't exist.
     *
     * @param string $coreName
     * @return bool
     * @throws Exception
     */
    public function createCoreIfNotExists(string $coreName): bool
    {
        if ($this->checkCoreExists($coreName)) {
            return true;
        }

        return $this->createCore($coreName);
    }

    /**
     * Check if a Solr core exists.
     *
     * @param string $coreName
     * @return bool
     */
    public function checkCoreExists(string $coreName): bool
    {

        $url = $this->buildUrl('admin/cores', ['action' => 'STATUS', 'core' => $coreName]);

        try {
            $response = $this->makeRequest($url);
            $result = json_decode($response, true);

            return is_array($result['status'][$coreName]) && count($result['status'][$coreName])>0;

        } catch (Exception $e) {
            $this->logError("Error checking core existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a Solr core.
     *
     * @param string $coreName
     * @return bool
     * @throws Exception
     */
    public function createCore(string $coreName): bool
    {

        if ($this->checkCoreExists($coreName)) {
            throw new Exception("Core '{$coreName}' already exists in Solr.");
        }

        $url = $this->buildUrl('admin/cores', ['action' => 'CREATE', 'name' => $coreName, 'configSet' => '_default']);

        try {
            $response = $this->makeRequest($url);
            $result = json_decode($response, true);

            if (isset($result['responseHeader']['status']) && $result['responseHeader']['status'] === 0) {
                $this->logInfo("Core '{$coreName}' created successfully.");

                return true;
            } else {
                throw new Exception("Failed to create core '{$coreName}'. " . ($result['error']['msg'] ?? 'Unknown error occurred.'));
            }
        } catch (Exception $e) {
            throw new Exception("Error creating core '{$coreName}': " . $e->getMessage());
        }
    }

    public function updateCore(string $oldCoreName, string $newCoreName): bool
    {
        if (!$this->checkCoreExists($oldCoreName)) {
            throw new Exception("Core '{$oldCoreName}' does not exist in Solr.");
        }

        $url = $this->buildUrl('admin/cores', ['action' => 'RENAME', 'core' => $oldCoreName, 'other' => $newCoreName, 'configSet' => '_default']);

        try {
            $response = $this->makeRequest($url);
            $result = json_decode($response, true);

            if (isset($result['responseHeader']['status']) && $result['responseHeader']['status'] === 0) {
                $this->logInfo("Core $oldCoreName to '{$newCoreName}' updated  successfully.");
                return true;
            } else {
                throw new Exception("Failed to updated  core $oldCoreName to '{$newCoreName}'. " . ($result['error']['msg'] ?? 'Unknown error occurred.'));
            }
        } catch (Exception $e) {
            throw new Exception("Error updated  core $oldCoreName to '{$newCoreName}': " . $e->getMessage());
        }
    }

    /**
     * Delete a Solr core.
     *
     * @param string $coreName
     * @return array
     * @throws Exception
     */
    public function deleteCore(string $coreName): array
    {
        $url = $this->buildUrl('admin/cores', ['action' => 'UNLOAD', 'core' => $coreName]);

        try {
            $response = $this->makeRequest($url);

            // Check if the response is empty or not valid JSON
            if (empty($response)) {
                throw new Exception("Empty response received while deleting core '{$coreName}'.");
            }

            $decodedResponse = json_decode($response, true);

            // Check if the decoding was successful
            if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON response received while deleting core '{$coreName}': " . json_last_error_msg());
            }

            return $decodedResponse;
        } catch (Exception $e) {
            throw new Exception("Error deleting core '{$coreName}': " . $e->getMessage());
        }
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

    /**
     * Make an HTTP request to Solr.
     *
     * @param string $url
     * @return string
     * @throws Exception
     */
    protected function makeRequest(string $url): string
    {
        $response = @file_get_contents($url);

        if ($response === false) {
            throw new Exception("Failed to make request to Solr: " . error_get_last()['message']);
        }

        return $response;
    }

    /**
     * Log an info message.
     *
     * @param string $message
     */
    private function logInfo(string $message): void
    {
        $this->output->writeln("<info>{$message}</info>");
    }

    /**
     * Log an error message.
     *
     * @param string $message
     */
    private function logError(string $message): void
    {
        $this->output->writeln("<error>{$message}</error>");
    }
}
