<?php

namespace Reliv\RcmAxosoft\Log;

use Psr\Log\LoggerInterface;
use RcmErrorHandler2\Log\AbstractErrorLogger;
use Reliv\AxosoftApi\Model\GenericApiRequest;
use Reliv\AxosoftApi\V5\ApiCreate\AbstractApiRequestCreate;
use Reliv\AxosoftApi\V5\Items\ApiRequestList;
use Reliv\RcmAxosoft\Exception\AxosoftLoggerException;

/**
 * Class AxosoftLoggerPsr
 *
 * PHP version 5
 *
 * @category  Reliv
 * @package   Reliv\RcmAxosoft
 * @author    James Jervis <jjervis@relivinc.com>
 * @copyright 2016 Reliv International
 * @license   License.txt New BSD License
 * @version   Release: <package_version>
 * @link      https://github.com/reliv
 */
class AxosoftLoggerPsr extends AbstractErrorLogger implements LoggerInterface
{
    /**
     * array(
     * 'itemType' => 'defects', // Bug
     * 'projectId' => 10
     * 'enterIssueIfNotStatus' => array(
     *   'closed',
     *   'resolved',
     *  ),
     * ),
     *
     * @var array $options
     */
    protected $options = [];

    /**
     * @var \Reliv\AxosoftApi\Service\AxosoftApi $api
     */
    protected $api = null;

    /**
     * @var array Track the submitted items 'Summary' => DateTime
     * - NOTE: This may cause memory issues for long running processes
     */
    protected $submitted = [];

    /**
     * @param \mixed $api
     * @param array $options
     */
    public function __construct($api, $options = [])
    {
        $this->api = $api;
        $options = array_merge($options, $this->options);
        parent::__construct($options);
    }

    /**
     * getApi
     *
     * @return \Reliv\AxosoftApi\Service\AxosoftApi|null
     */
    protected function getApi()
    {
        return $this->api;
    }

    /**
     * getItemObject
     *
     * @return AbstractApiRequestCreate
     */
    protected function getItemObject()
    {
        $itemType = $this->getOption('itemType', 'defect');

        return ItemTypeCreateMap::getItemObject($itemType);
    }

    /**
     * addSubmitted
     *
     * @param $summary
     *
     * @return void
     */
    protected function addSubmitted($summary)
    {
        $this->submitted[$summary] = new \DateTime();
    }

    /**
     * removeSubmitted
     *
     * @param $summary
     *
     * @return void
     */
    protected function removeSubmitted($summary)
    {
        unset($this->submitted[$summary]);
    }

    /**
     * getSubmittedTime
     *
     * @param $summary
     *
     * @return null
     */
    protected function getSubmittedTime($summary)
    {
        if (isset($this->submitted[$summary])) {
            return $this->submitted[$summary];
        }

        return null;
    }

    /**
     * canCreate
     *
     * @return bool
     */
    protected function canCreate($summary)
    {
        $existing = $this->getSubmittedTime($summary);

        if ($existing === null) {
            return true;
        }

        $tryResubmitTimeout = $this->getOption('tryResubmitTimeout', 5);

        $now = new \DateTime();

        $diff = $now->getTimestamp() - $existing->getTimestamp();

        if ($diff >= $tryResubmitTimeout) {
            $this->removeSubmitted($summary);

            return true;
        }

        return false;
    }
    
    /**
     * log
     *
     * @param int $priority
     * @param mixed $message
     * @param array $extra
     *
     * @return $this
     */
    public function log($priority, $message, array $extra = [])
    {
        $summary = $this->prepareSummary($priority, $message);

        $existingItem = $this->getExistingItem($summary);

        if ($existingItem) {
            // Add comment
            $this->addComment($existingItem, $summary, $extra);

            return $this;
        }

        // create issue
        $this->createIssue($summary, $extra);

        return $this;
    }

    /**
     * getExistingItem
     *
     * @param $summary
     *
     * @return mixed
     * @throws AxosoftLoggerException
     */
    protected function getExistingItem($summary)
    {
        $api = $this->getApi();

        $request = new ApiRequestList();
        $request->setProjectId($this->getOption('projectIdToCheckForIssues', 0));
        $request->setSearchString($this->prepareSearchString($summary));
        $request->setSearchField('name');
        $request->setSortFields('created_date_time');

        $response = $api->send($request);

        if ($api->hasError($response)) {
            throw new AxosoftLoggerException('Existing item search failed. '
                . $response->getMessage());
        }

        $data = $response->getData();

        if (count($data) < 1) {
            return null;
        }

        $enterIssueIfNotStatus = $this->getOption('enterIssueIfNotStatus', []);

        $existingItem = null;

        foreach ($data as $item) {
            if (!in_array($item['status']['name'], $enterIssueIfNotStatus)) {
                // we return the first one we find
                return $item;
            }
        }

        return null;
    }

    /**
     * addComment
     *
     * @param       $existingItem
     * @param       $summary
     * @param array $extra
     *
     * @return void
     * @throws \Exception
     */
    protected function addComment($existingItem, $summary, $extra = [])
    {
        $updateData = [];
        $updateDate = new \DateTime();

        $updateData['notify_customer'] = false;
        $updateData['item'] = []; //$data[0];

        $updateData['item']['description'] = $existingItem['description']
            . "<br/>- Error occured again: "
            . $updateDate->format(\DateTime::W3C)
            . " " . $summary;

        //$updateData['item']['notes'] =
        // $existingItem['notes']
        // . "/n-This has been added on "
        // . $updateDate->format(\DateTime::W3C);

        $updateData['item']['id'] = $existingItem['id'];

        $updateUrl = '/api/v5/' . $existingItem['item_type']
            . '/' . $existingItem['id'];

        $request = new GenericApiRequest($updateUrl, 'POST', $updateData);

        $api = $this->getApi();

        $response = $api->send($request);

        if ($api->hasError($response)) {
            throw new AxosoftLoggerException('Could and comment to item. '
                . $response->getMessage());
        }
    }

    /**
     * createIssue
     *
     * @param       $summary
     * @param array $extra
     *
     * @return void
     * @throws \Exception
     */
    protected function createIssue($summary, $extra = [])
    {
        if (!$this->canCreate($summary)) {
            return;
        }

        // Add a new defect
        $request = $this->getItemObject();

        $description = $this->getDescription($extra);

        $request->setDescription($description);
        $request->setName($summary);
        $request->setProject($this->getOption('projectId', 0));

        $releaseId = $this->getOption('releaseId');
        if ($releaseId) {
            $request->setRelease($releaseId);
        }

        $api = $this->getApi();
        $response = $api->send($request);

        if ($api->hasError($response)) {
            throw new AxosoftLoggerException('Could not create item. '
                . $response->getMessage());
        }

        $this->addSubmitted($summary);
    }

    /**
     * getDescription
     *
     * @param array $extra
     * @param string $lineBreak
     *
     * @return mixed|string
     */
    protected function getDescription($extra = [], $lineBreak = '<br/>')
    {
        $description = parent::getDescription($extra, $lineBreak);

        $description = str_replace("\n", $lineBreak, $description);

        return $description;
    }

    /**
     * prepareSummary
     *
     * @param $priority
     * @param $message
     *
     * @return mixed|string
     */
    protected function prepareSummary($priority, $message)
    {
        $summary = parent::prepareSummary($priority, $message);

        // Limit is 150 chars, we add quotes and dots, so we have 145 chars left
        $summary = substr($summary, 0, 145) . '...';

        return $summary;
    }

    /**
     * prepareSearchString
     *
     * @param $searchString
     *
     * @return string
     */
    protected function prepareSearchString($searchString)
    {
        // Add proper quotes
        return '"' . $searchString . '"';
    }
}
