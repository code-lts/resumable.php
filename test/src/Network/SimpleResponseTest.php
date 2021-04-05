<?php
namespace ResumableJs\Network;

use ResumableJs\Network\Response;
use PHPUnit\Framework\TestCase;

/**
 * Class SimpleResponseTest
 * @package ResumableJs\Network
 * @property $response Response
 */
class SimpleResponseTest extends TestCase
{
    protected function setUp(): void
    {
        $this->response = new SimpleResponse();
    }

    public function tearDown(): void
    {
        unset($this->response);
        parent::tearDown();
    }


    public function headerProvider()
    {
        return array(
            array(404,404),
            array(204,204),
            array(200,200),
            array(500,204),
        );
    }

    /**
     * @runInSeparateProcess
     * @dataProvider headerProvider
     */
    public function testHeader($statusCode, $expectd)
    {
       $this->response->header($statusCode);
       $this->assertEquals($expectd, http_response_code());

    }

}
