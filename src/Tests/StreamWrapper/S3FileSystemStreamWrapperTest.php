<?php

namespace Drupal\S3FileSystem\Tests\StreamWrapper;

use Aws\S3\S3Client;
use Drupal\s3filesystem\AWS\S3\DrupalAdaptor;
use Drupal\s3filesystem\S3FileSystemStreamWrapper;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;


/**
 * Class S3FileSystemStreamWrapperTest
 *
 * @author      Andy Thorne <andy.thorne@timeinc.com>
 * @copyright   Time Inc (UK) 2014
 *
 * @name        S3FileSystem
 * @group       Ensure that the remote file system functionality provided by S3 File System works correctly.
 * @description S3 File System
 */
class S3FileSystemStreamWrapperTest extends UnitTestCase
{
    /**
     * @param array $methods
     *
     * @return S3FileSystemStreamWrapper
     */
    protected function getWrapper(array $methods = null, \Closure $configClosure = null)
    {
        $wrapper = $this->getMockBuilder('Drupal\s3filesystem\S3FileSystemStreamWrapper')
            ->disableOriginalConstructor()
            ->setMethods($methods)
            ->getMock();

        $s3Client      = $this->getMockBuilder('Aws\S3\S3Client')->disableOriginalConstructor()->getMock();
        $drupalAdaptor = new DrupalAdaptor($s3Client);

        $config = $this->getMockBuilder('Drupal\s3filesystem\StreamWrapper\Configuration')
            ->setMethods(array(
                'log',
                'getDefaultSettings',
                'isRequestSecure',
                'getHttpHost',
            ))
            ->getMock();

        $testConfig = array(
            's3filesystem.settings' => array(
                's3'  =>
                    array(
                        'bucket'         => 'test-bucket',
                        'keyprefix'      => 'testprefix',
                        'region'         => 'eu-west-1',
                        'force_https'    => false,
                        'ignore_cache'   => false,
                        'refresh_prefix' => '',
                        'custom_host'    =>
                            array(
                                'enabled'  => false,
                                'hostname' => null,
                            ),
                        'custom_cdn'     =>
                            array(
                                'enabled'   => false,
                                'domain'    => 'assets.domain.co.uk',
                                'http_only' => true,
                            ),
                        'presigned_urls' =>
                            array(),
                        'saveas'         =>
                            array(),
                        'torrents'       =>
                            array(),
                        'custom_s3_host' =>
                            array(
                                'enabled'  => false,
                                'hostname' => '',
                            ),
                    ),
                'aws' =>
                    array(
                        'use_instance_profile' => false,
                        'default_cache_config' => '/tmp',
                        'access_key'           => 'INVALID',
                        'secret_key'           => 'INVALID',
                        'proxy'                =>
                            array(
                                'enabled'         => false,
                                'host'            => 'proxy:8080',
                                'connect_timeout' => 10,
                                'timeout'         => 20,
                            ),
                    ),
            )
        );

        if($configClosure instanceof \Closure)
        {
            $configClosure($testConfig);
        }

        $settings = $this->getConfigFactoryStub($testConfig);


        $config->expects($this->once())
            ->method('getDefaultSettings')->willReturn($settings->get('s3filesystem.settings'));

        $config->expects($this->atLeastOnce())
            ->method('isRequestSecure')->willReturn(true);

        if($testConfig['s3filesystem.settings']['s3']['custom_host']['enabled'])
        {
            $config->expects($this->once())
                ->method('getHttpHost')->willReturn('test.localhost');

        }

        if(!$testConfig['s3filesystem.settings']['s3']['custom_cdn']['enabled'])
        {
            $s3Client->expects($this->any())
                ->method('getObjectUrl')->willReturn('region.amazonaws.com/path/to/test.png');
        }

        $config->configure();
        $mimeTypeGuesser = $this->getMock('Drupal\Core\File\MimeType\MimeTypeGuesser');

        /** @var $wrapper S3FileSystemStreamWrapper */
        $wrapper->setUp(
            $drupalAdaptor,
            $s3Client,
            $config,
            $mimeTypeGuesser,
            new NullLogger()
        );

        return $wrapper;
    }

    public function testSetUriWithPrefix()
    {
        $prefix  = 'testprefix';
        $wrapper = $this->getWrapper(null, function (&$config) use ($prefix)
        {
            $config['s3filesystem.settings']['s3']['keyprefix'] = $prefix;
        });

        $wrapper->setUri('s3://test.png');
        $this->assertEquals('s3://' . $prefix . '/test.png', $wrapper->getUri());
    }

    public function testSetUriWithNoPrefix()
    {
        $wrapper = $this->getWrapper(null, function (&$config)
        {
            $config['s3filesystem.settings']['s3']['keyprefix'] = null;
        });

        $wrapper->setUri('s3://test.png');
        $this->assertEquals('s3://test.png', $wrapper->getUri());
    }

    public function testExternalUrlWithCustomCDN()
    {
        $wrapper = $this->getWrapper(null, function (&$config)
        {
            $config['s3filesystem.settings']['s3']['custom_cdn']['enabled'] = true;
        });

        $wrapper->setUri('s3://test.png');
        $url = $wrapper->getExternalUrl();
        $this->assertEquals('assets.domain.co.uk/testprefix/test.png', $url);
    }

    public function testExternalUrl()
    {
        $wrapper = $this->getWrapper();

        $wrapper->setUri('s3://test.png');
        $url = $wrapper->getExternalUrl();
        $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png', $url);
    }

    public function testExternalUrlWithTorrents()
    {
        $wrapper = $this->getWrapper(null, function (&$config)
        {
            $config['s3filesystem.settings']['s3']['torrents'] = array(
                'torrent/'
            );
        });

        $wrapper->setUri('s3://torrent/test.png');
        $url = $wrapper->getExternalUrl();
        $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png?torrent', $url);
    }

    public function testExternalUrlWithSaveAs()
    {
        $wrapper = $this->getWrapper(null, function (&$config)
        {
            $config['s3filesystem.settings']['s3']['saveas'] = array(
                'saveas/'
            );

            $config['s3filesystem.settings']['s3']['torrents'] = array(
                'torrent/'
            );
        });

        $wrapper->setUri('s3://saveas/test.png');
        $url = $wrapper->getExternalUrl();
        $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png', $url);
    }

    public function testExternalUrlWithPresignedUrl()
    {
        $wrapper = $this->getWrapper(null, function (&$config)
        {
            $config['s3filesystem.settings']['s3']['presigned_url'] = array(
                'presigned_url/'
            );

            $config['s3filesystem.settings']['s3']['torrents'] = array(
                'torrent/'
            );
        });

        $wrapper->setUri('s3://presigned_url/test.png');
        $url = $wrapper->getExternalUrl();
        $this->assertEquals('region.amazonaws.com/testprefix/path/to/test.png', $url);
    }

    public function testDirectoryPath()
    {
        $wrapper = $this->getWrapper();
        $directoryPath = $wrapper->getDirectoryPath();

        $this->assertEquals('s3/files', $directoryPath);
    }


    public function testChmod()
    {
        $wrapper = $this->getWrapper();
        $this->assertTrue($wrapper->chmod('s3://test.png'));
    }

    public function testRealpath()
    {
        $wrapper = $this->getWrapper();
        $this->assertFalse($wrapper->realpath('s3://test.png'));
    }

    public function testDirname()
    {
        $wrapper = $this->getWrapper();

        $dirName = $wrapper->dirname('s3://directory/subdirectory/test.png');
        $this->assertEquals('s3://directory/subdirectory', $dirName);

        $dirName = $wrapper->dirname($dirName);
        $this->assertEquals('s3://directory', $dirName);
    }
}
