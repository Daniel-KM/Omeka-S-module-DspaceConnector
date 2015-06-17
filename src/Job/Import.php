<?php
namespace DspaceConnector\Job;

use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use DspaceConnector\Entity\DspaceItem;
use Zend\Http\Client;
use SimpleXMLElement;

class Import extends AbstractJob
{
    protected $client;
    
    protected $apiUrl;
    
    protected $api;
    
    protected $termIdMap;
    
    protected $addedCount;
    
    protected $updatedCount;
    
    protected $itemSetId;
    
    public function perform()
    {
        $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $this->addedCount = 0;
        $this->updatedCount = 0;
        $this->prepareTermIdMap();
        $this->client = $this->getServiceLocator()->get('Omeka\HttpClient');
        $this->client->setHeaders(array('Accept' => 'application/json'));
        $this->apiUrl = $this->getArg('api_url');
        $this->importCollection($this->getArg('collection_link'));
        $comment = $this->getArg('comment');
        $dspaceImportJson = array(
                            'o:job'         => array('o:id' => $this->job->getId()),
                            'comment'       => $comment,
                            'added_count'   => $this->addedCount,
                            'updated_count' => $this->updatedCount
                          );
        $response = $this->api->create('dspace_imports', $dspaceImportJson);
        if ($response->isError()) {
            echo 'fail creating dspace import';
            throw new \Exception('fail creating dspace import');
        }
        
    }

    public function importCollection($collectionLink)
    {
        $response = $this->getResponse($collectionLink, 'items');
        if ($response) {
            $collection = json_decode($response->getBody(), true);
            //set the item set it. called here so that, if a new item set needs
            //to be created from the collection data, I have the data to do so
            $this->setItemSetId($collection);
            foreach ($collection['items'] as $itemData) {
                $oresponse = $this->api->search('dspace_items', 
                                                array('remote_id' => $itemData['id'],
                                                      'api_url' => $this->apiUrl
                                                ));
                $content = $oresponse->getContent();
                $this->importItem($itemData['link']);
            }
        }
    }

    public function importItem($itemLink)
    {

        $response = $this->getResponse($itemLink, 'metadata,bitstreams');
        if ($response) {
            $itemArray = json_decode($response->getBody(), true);
        }
        $itemJson = array();
        if ($this->itemSetId) {
            $itemJson['o:item_set'] = array(array('o:id' => $this->itemSetId));
        }
        $itemJson = $this->processItemMetadata($itemArray['metadata'], $itemJson);
        if ($this->getArg('ingest_files')) {
            $itemJson = $this->processItemBitstreams($itemArray['bitstreams'], $itemJson);
        }
        $dspaceId = $itemArray['id'];
        //see if the item has already been imported
        $response = $this->api->search('dspace_items', 
                                        array(
                                              'api_url'   => $this->apiUrl,
                                              'remote_id' => $dspaceId
                                        ));
        $content = $response->getContent();
        if (empty ($content)) {
            $dspaceItem = false;
            $omekaItem = false;
        } else {
            $dspaceItem = $content[0];
            $omekaItem = $dspaceItem->item();
        }
        
        if ($omekaItem) {
            $response = $this->api->update('items', $omekaItem->id(), $itemJson);
            $this->updatedCount++;
        } else {
            $response = $this->api->create('items', $itemJson);
            $this->addedCount++;
        }
        
        if ($response->isError()) {
            echo 'error';
            print_r( $response->getErrors() );
            throw new Exception\RuntimeException('There was an error during item creation.');
        }
        
        $itemId = $response->getContent()->id();
        $dspaceItemJson = array(
                            'o:job'     => array('o:id' => $this->job->getId()),
                            'o:item'    => array('o:id' => $itemId),
                            'api_url'   => $this->apiUrl,
                            'remote_id' => $itemArray['id'],
                            'handle'    => $itemArray['handle'],
                            'last_modified' => new \DateTime($itemArray['lastModified'])
                        );
        
        if ($dspaceItem) {
            $response = $this->api->update('dspace_items', $dspaceItem->id(), $dspaceItemJson);
        } else {
            $response = $this->api->create('dspace_items', $dspaceItemJson);
        }
        
        if ($response->isError()) {
            throw new Exception\RuntimeException('There was an error during dspace item creation.');
        }
    }
    
    public function processItemMetadata($itemMetadataArray, $itemJson)
    {
        foreach ($itemMetadataArray as $metadataEntry) {
            $terms = $this->mapKeyToTerm($metadataEntry['key']);

            foreach ($terms as $term) {
                //skip non-understood or mis-written terms
                if (isset($this->termIdMap[$term])) {
                    $valueArray = array();
                    if ($term == 'bibo:uri') {
                        $valueArray['@id'] = $metadataEntry['value'];
                    } else {
                        $valueArray['@value'] = $metadataEntry['value'];
                        if (isset($metadataEntry['language'])) {
                            $valueArray['@language'] = $metadataEntry['language'];
                        }
                    }
                    $valueArray['property_id'] = $this->termIdMap[$term];
                    $itemJson[$term][] = $valueArray;
                }
            }
        }
        return $itemJson;
    }
    
    public function processItemBitstreams($bitstreamsArray, $itemJson)
    {
        foreach($bitstreamsArray as $bitstream) {
            $itemJson['o:media'][] = array(
                'o:type'     => 'url',
                'o:data'     => json_encode($bitstream),
                'o:source'   => $this->apiUrl . $bitstream['link'],
                'ingest_url' => $this->apiUrl . '/rest' . $bitstream['retrieveLink'],
                'dcterms:title' => array(
                    array(
                        '@value' => $bitstream['name'],
                        'property_id' => $this->termIdMap['dcterms:title']
                    ),
                ),
            );
        }
        return $itemJson;
    }

    public function getResponse($link, $expand = 'all')
    {
        
        //work around some dspace api versions reporting RESTapi instead of rest in the link
        $link = str_replace('RESTapi', 'rest', $link);
        $this->client->setUri($this->apiUrl . $link);
        $this->client->setParameterGet(array('expand' => $expand));
        
        $response = $this->client->send();
        if (!$response->isSuccess()) {
            throw new Exception\RuntimeException(sprintf(
                'Requested "%s" got "%s".', $this->apiUrl . $link, $response->renderStatusLine()
            ));
        }
        return $response;
    }

    protected function mapKeyToTerm($key)
    {
        $parts = explode('.', $key);
        //only using dc. Don't know if DSpace ever emits anything else
        //(except for the subproperties listed below that aren't actually in dcterms
        if ($parts[0] != 'dc') {
            return array();
        }
        
        if (count($parts) == 2) {
            return array('dcterms:' . $parts[1]);
        }
        
        if (count($parts) == 3) {
            //liberal mapping onto superproperties by default
            $termsArray = array('dcterms:' . $parts[1]);
            //parse out refinements where known
            switch ($parts[2]) {
                case 'author' :
                    $termsArray[] = "dcterms:creator";
                break;
                
                case 'abstract' :
                    $termsArray[] = "dcterms:abstract";
                break;
                
                case 'uri' : 
                    $termsArray[] = "bibo:uri";
                break;
                case 'iso' : //handled by superproperty dcterms:language
                case 'editor' : //handled as dcterms:contributor
                case 'accessioned' : //ignored
                break;
                default :
                    $termsArray[] = 'dcterms:' . $parts[2]; 
            }
            return $termsArray;
        }
    }
    
    protected function prepareTermIdMap()
    {
        $this->termIdMap = array();
        $properties = $this->api->search('properties', array(
            'vocabulary_namespace_uri' => 'http://purl.org/dc/terms/'
        ))->getContent();
        foreach ($properties as $property) {
            $term = "dcterms:" . $property->localName();
            $this->termIdMap[$term] = $property->id();
        }

        $properties = $this->api->search('properties', array(
            'vocabulary_namespace_uri' => 'http://purl.org/ontology/bibo/'
        ))->getContent();
        foreach ($properties as $property) {
            $term = "bibo:" . $property->localName();
            $this->termIdMap[$term] = $property->id();
        }
    }
    
    protected function setItemSetId($collection)
    {
        $itemSetId = $this->getArg('itemSet', false);
        if ($itemSetId == 'new') {
            $itemSet = $this->createItemSet($collection);
            $this->itemSetId = $itemSet->id();
        } else {
            $this->itemSetId = $itemSetId; 
        }
    }
    
    protected function createItemSet($collection)
    {
        $itemSetData = array();
        $titlePropId = $this->termIdMap['dcterms:title'];
        $descriptionPropId = $this->termIdMap['dcterms:description'];
        $rightsPropId = $this->termIdMap['dcterms:rights'];
        $licensePropId = $this->termIdMap['dcterms:license'];
        //$itemSetData[$titlePropId] = array();
        
        $response = $this->api->create('item_sets', $itemSetData);
        return $response->getContent();
    }
}