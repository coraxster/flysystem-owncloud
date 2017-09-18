<?php

namespace League\Flysystem\OwnCloud;


use League\Flysystem\WebDAV\WebDAVAdapter;
use LogicException;
use Sabre\DAV\Client;
use Sabre\DAV\Exception\NotFound;
use \GuzzleHttp\Client as Guzzle;

/**
 * Class OwnCloudAdapter
 * @package League\Flysystem\OwnCloud
 */
class OwnCloudAdapter extends WebDAVAdapter
{
    /**
     * @var array
     */
    public static $shareParams = [
        'permissions',
        'password',
        'publicUpload',
        'expireDate'
    ];
    /**
     * @var bool
     */
    protected $OcsConfig;

    /**
     * OwnCloudAdapter constructor.
     * @param Client $client
     * @param null $prefix
     * @param bool $useStreamedCopy
     * @param bool $OcsConfig
     */
    public function __construct(Client $client, $prefix = null, $useStreamedCopy = true, $OcsConfig = false)
    {
        if (! $OcsConfig){
            throw new LogicException('Not presented OCS configuration');
        }
        parent::__construct($client, $prefix, $useStreamedCopy);
        $this->OcsConfig = $OcsConfig;
    }



    /**
     * Implement for url() method
     *
     * @param $path
     * @return mixed
     */
    public function getUrl($path)
    {
        return $this->createShare($path)['url'];
    }

    /**
     * Override method for Owncloud support
     *
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $location = $this->applyPathPrefix($this->encodePath($path));
        $newLocation = $this->applyPathPrefix($this->encodePath($newpath));

        try {
            $response = $this->client->request('MOVE', ltrim($location, '/'), null, [
                'Destination' => $this->client->getAbsoluteUrl( ltrim($newLocation, '/')),
            ]);

            if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
                return true;
            }
        } catch (NotFound $e) {
            // Would have returned false here, but would be redundant
        }

        return false;
    }


    /**
     * Methods available directly from adapter
     */

    /**
     * @param $path
     * @param bool $subfiles
     * @return array
     */
    public function getShares($path, $subfiles = false)
    {
        $guzzle = $this->getGuzzleClient();
        $response = $guzzle->request('GET',
            '' , [
                'path' => $path,
                'reshares' => true,
                'subfiles' => $subfiles
            ]);
        return $this->parseXmlFromOCS($response->getBody());
    }

    /**
     * @param $path
     * @return array
     */
    public function createShare($path)
    {
        $guzzle = $this->getGuzzleClient();
        $response = $guzzle->request('POST',
            '', [
                'form_params' => [
                    'path' => $path,
                    'shareType' => '3',
                    'permissions' => '1'
                ]
            ]);
        return $this->parseXmlFromOCS($response->getBody());
    }

    /**
     * @param $share_id
     * @return array
     */
    public function getShareById($share_id)
    {
        $guzzle = $this->getGuzzleClient();
        $response = $guzzle->request('GET',
            '/' . $share_id, [
            ]);
        return $this->parseXmlFromOCS($response->getBody());
    }

    /**
     * @param $share_id
     * @param array $params
     * @return bool
     */
    public function updateShareById($share_id, $params = [])
    {
        foreach ($params as $key=>$param) {
            if ( ! in_array($key, self::$shareParams)){
                continue;
            }
            $guzzle = $this->getGuzzleClient();
            $guzzle->request('PUT',
                '/' . $share_id, [
                    'form_params' => [
                        $key => $param
                    ]
                ]);
        }
        return true;
    }

    /**
     * @param $share_id
     * @return bool
     */
    public function deleteShareById($share_id)
    {
        $guzzle = $this->getGuzzleClient();
        $guzzle->request('DELETE',
            '/' . $share_id, [
            ]);
        return true;
    }




    /**
     * @return Guzzle
     */
    protected function getGuzzleClient()
    {
        return new Guzzle(
            [
                'base_uri' => $this->OcsConfig['shareApi'],
                'auth' => [
                    $this->OcsConfig['userName'],
                    $this->OcsConfig['password']
                ],
                'headers' => ['OCS-APIRequest' => 'true'],
            ]);
    }

    /**
     * @param $body
     * @param array $config
     * @return array
     * @throws \Exception
     */
    protected function parseXmlFromOCS($body, $config = [])
    {
        $disableEntities = libxml_disable_entity_loader(true);
        $internalErrors = libxml_use_internal_errors(true);

        try {
            // Allow XML to be retrieved even if there is no response body
            $xml = new \SimpleXMLElement(
                (string) $body ?: '<root />',
                isset($config['libxml_options']) ? $config['libxml_options'] : LIBXML_NONET,
                false,
                isset($config['ns']) ? $config['ns'] : '',
                isset($config['ns_is_prefix']) ? $config['ns_is_prefix'] : false
            );
            libxml_disable_entity_loader($disableEntities);
            libxml_use_internal_errors($internalErrors);
        } catch (\Exception $e) {
            libxml_disable_entity_loader($disableEntities);
            libxml_use_internal_errors($internalErrors);
            throw new \Exception(
                'Unable to parse response body into XML: ' . $e->getMessage()
            );
        }

        return [
            'url' => ((string)($xml->data->url)) ?? false,
            'token' => ((string)($xml->data->token)) ?? false,
            'id' => ((string)($xml->data->id)) ?? false,
            'xml' => $xml
        ];
    }
}
