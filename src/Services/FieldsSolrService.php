<?php

namespace HaiderJabbar\LaravelSolr\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;


class FieldsSolrService
{
    private ConsoleOutput $output;
    private string $solrUrl;
    private string $solrUsername;
    private string $solrPassword;
    private Client $client;

    /**
     * CoreSolrService constructor.
     * Initializes the service with necessary dependencies.
     */
    public function __construct(ConsoleOutput $output)
    {
        $this->output = $output;
        $this->solrUrl = config('solr.solr_url');
        $this->solrUsername = config("solr.solr_username");
        $this->solrPassword = config("solr.solr_password");
        $this->client = new Client(['base_uri' => $this->buildUrl('')]);
    }

    /**
     * Add multiple fields to a Solr core.
     *
     * @param string $coreName
     * @param array $fields
     * @throws Exception
     */
    public function addFieldsToCore(string $coreName, array $fields): bool
    {

        $this->createCoreIfNotExists($coreName);
        $addedFields = [];
        $this->output->writeln("Starting to add fields to core: $coreName");

        foreach ($fields as $field) {
            try {
                $this->modifyField($coreName, $field, 'add-field');
                $addedFields[] = $field['name'];
                $this->logSuccess("add", $coreName, $field['name']);
            } catch (Exception $e) {
                $this->handleError("add", $coreName, $field['name'], $e->getMessage());
                $this->rollbackFields($coreName, $addedFields, 'delete-field');
                throw new Exception("Migration halted: Field already exists in the Solr core.");
            }
        }
        $this->output->writeln("Fields added successfully.");
        return true;
    }

    /**
     * Update multiple fields in a Solr core.
     *
     * @param string $coreName
     * @param array $fields
     */
    public function updateFieldsInCore(string $coreName, array $fields): void
    {
        Log::info("Updating fields in core: {$coreName}");
        $this->createCoreIfNotExists($coreName);
        $updatedFields = [];

        foreach ($fields as $field) {
            try {
                $this->modifyField($coreName, $field, 'replace-field');
                $updatedFields[] = $field['name'];
                $this->logSuccess("update", $coreName, $field['name']);
            } catch (Exception $e) {
                $this->handleError("update", $coreName, $field['name'], $e->getMessage());
                $this->rollbackFields($coreName, $updatedFields, 'delete-field');
            }
        }
    }

    /**
     * Delete multiple fields from a Solr core.
     *
     * @param string $coreName
     * @param array $fieldNames
     * @throws Exception if no fields were successfully deleted
     */
    public function deleteFieldsFromCore(string $coreName, array $fieldNames): void
    {
        Log::info("Deleting fields from core: {$coreName}");
        $successCount = 0;

        foreach ($fieldNames as $fieldName) {
            try {
                $this->modifyField($coreName, ['name' => $fieldName], 'delete-field');
                $this->logSuccess("delete", $coreName, $fieldName);
                $successCount++;
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $this->handleError("delete", $coreName, $fieldName, $errorMessage);
            }
        }

        if ($successCount === 0) {
            throw new Exception("Failed to delete any fields from core '{$coreName}'.");
        }

        $this->output->writeln("<info>Deleted {$successCount} out of " . count($fieldNames) . " fields from core '{$coreName}'.</info>");
    }

    /**
     * Get all fields from a Solr core.
     *
     * @param string $coreName
     * @return array
     */
    public function getCoreFields(string $coreName): array | JsonResponse
    {
        $solrUrl = $this->buildUrl('schema/fields');
        logger($solrUrl);
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->get($solrUrl);
            // Check if the response was successful
            if ($response->successful()) {
                // Return only the fields
                return $response->json('fields') ?? [];
            } else {
                // Handle the error, log the issue, or return a failure message
                return response()->json(['error' => 'Solr request failed'], 500);
            }
        } catch (\Exception $e) {
            // Handle any exceptions
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a Solr core if it doesn't exist.
     *
     * @param string $coreName
     */
    private function createCoreIfNotExists(string $coreName): void
    {
        // Implementation of createCoreIfNotExists method
        // This method should check if the core exists and create it if it doesn't
    }


    /**
     * Prepare field data for Solr API request.
     *
     * @param array $field
     * @return array
     */
    private function prepareFieldData(array $field): array
    {
        return [
            'name' => $field['name'],
            'type' => $field['type'],
            'stored' => $field['stored'] ?? true,
            'indexed' => $field['indexed'] ?? true,
            'required' => $field['required'] ?? false,
            'multiValued' => $field['multiValued'] ?? false,
        ];
    }

    /**
     * Rollback fields in case of an error.
     *
     * @param string $coreName
     * @param array $fields
     * @param string $action
     */
    public function rollbackFields(string $coreName, array $fields, string $action = 'delete-field'): void
    {
        foreach ($fields as $fieldName) {
            try {
                $this->modifyField($coreName, ['name' => $fieldName], $action);
                $this->logSuccess("rollback", $coreName, $fieldName);
            } catch (Exception $e) {
                $this->handleError("rollback", $coreName, $fieldName, $e->getMessage());
            }
        }
    }

    //----------------------------------------------------------------------------
    private function modifyField(string $coreName, array $field, string $action): void
    {

        if ($action === 'delete-field') {
            $data = [$action => ['name' => $field['name']]];
        } else {
            $data = [$action => $this->prepareFieldData($field)];
        }


        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($this->buildUrl("{$coreName}/schema"), $data);

        $result = $response->json();

        if (!$response->successful() || !isset($result['responseHeader']['status']) || $result['responseHeader']['status'] !== 0) {
            $errorMessage = $result['error']['msg'] ?? 'Unknown error occurred.';
            throw new Exception($errorMessage);
        }
    }

    private function logSuccess(string $action, string $coreName, string $fieldName): void
    {
        $message = ucfirst($action) . "d field '{$fieldName}' successfully in core '{$coreName}'.";
        $this->output->writeln("<info>{$message}</info>");
        Log::info($message);
    }

    private function handleError(string $action, string $coreName, string $fieldName, string $errorMessage): void
    {
        $message = "Failed to {$action} field '{$fieldName}' in core '{$coreName}': {$errorMessage}";
        $this->output->writeln("<comment>{$message}</comment>");
        Log::warning($message);
    }

    /**
     * Build a URL for Solr API requests.
     *
     * @param string $endpoint
     * @param array $params
     * @return string
     */
    private function buildUrl(string $endpoint): string
    {
        if (!empty($this->solrUsername) && !empty($this->solrPassword)) {
            $url = $this->solrUrl . '/' . $endpoint;
            $url = Str::of($url)->replace('://', '://' . $this->solrUsername . ':' . $this->solrPassword . '@', $url);
        } else {
            $url = $this->solrUrl . '/' . $endpoint;
        }
        return $url;
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
    }
}
