<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\CarbonTrackController;
use CarbonTrack\Services\CarbonCalculatorService;

class CarbonTrackControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(CarbonTrackController::class));
    }

    public function testCalculateReturnsNumbers(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->getMockBuilder(CarbonCalculatorService::class)->disableOriginalConstructor()->onlyMethods(['calculateCarbonSavings'])->getMock();
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        // mock auth current user
        $auth->method('getCurrentUser')->willReturn(['id' => 1]);

        // mock activity lookup via CarbonActivity::findById uses PDO directly through the controller
        $activityStmt = $this->createMock(\PDOStatement::class);
        $activityStmt->method('execute')->willReturn(true);
        $activityStmt->method('fetch')->willReturn(['id' => 'uuid-1', 'unit' => 'km']);
        $pdo->method('prepare')->willReturn($activityStmt);

        // calculator output
        // adapt controller expect to use calculate or similar mapping
        $calc->method('calculateCarbonSavings')->willReturn([
            'carbon_savings' => 25.0
        ]);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);

        $request = makeRequest('POST', '/carbon-track/calculate', ['activity_id' => 'uuid-1', 'data' => 10]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->calculate($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(25.0, $json['data']['carbon_saved']);
        $this->assertEquals(250, $json['data']['points_earned']);
    }

    public function testCalculateMissingFields(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1]);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('POST', '/carbon-track/calculate', ['activity_id' => 'uuid-1']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->calculate($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testGetCarbonFactors(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('GET', '/carbon-track/factors');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getCarbonFactors($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $data = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($data['success']);
    }

    public function testGetUserStats(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1]);

        $summaryStmt = $this->createMock(\PDOStatement::class);
        $summaryStmt->method('execute')->willReturn(true);
        $summaryStmt->method('fetch')->willReturn([
            'total_records'=>3,
            'approved_records'=>1,
            'pending_records'=>1,
            'rejected_records'=>1,
            'total_carbon_saved'=>10.5,
            'total_points_earned'=>100
        ]);

        $monthlyStmt = $this->createMock(\PDOStatement::class);
        $monthlyStmt->method('execute')->willReturn(true);
        $monthlyStmt->method('fetchAll')->willReturn([
            ['month'=>'2025-01','records_count'=>1,'carbon_saved'=>5,'points_earned'=>50]
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($summaryStmt, $monthlyStmt);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('GET', '/carbon-track/stats');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserStats($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(3, $json['data']['overview']['total_records']);
        $this->assertCount(1, $json['data']['monthly']);
    }

    public function testSubmitRecordSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $calc->method('calculateCarbonSavings')->willReturn(['carbon_savings'=>12.3]);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $msg->expects($this->exactly(3))->method('sendMessage');
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->once())->method('log');
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'username'=>'user']);

        // find activity
        $activityStmt = $this->createMock(\PDOStatement::class);
        $activityStmt->method('execute')->willReturn(true);
        $activityStmt->method('fetch')->willReturn(['id'=>'a1','name_zh'=>'活动','unit'=>'km']);
        // insert record
        $insert = $this->createMock(\PDOStatement::class);
        $insert->method('execute')->willReturn(true);
        // select admins
        $admins = $this->createMock(\PDOStatement::class);
        $admins->method('execute')->willReturn(true);
        $admins->method('fetchAll')->willReturn([['id'=>9], ['id'=>10]]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($activityStmt, $insert, $admins);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('POST', '/carbon-track/record', ['activity_id'=>'a1','amount'=>5,'date'=>'2025-08-01']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->submitRecord($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(123, $json['calculation']['points_earned']);
    }

    public function testReviewRecordRejectFlow(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        // fetch record
        $fetch = $this->createMock(\PDOStatement::class);
        $fetch->method('execute')->willReturn(true);
        $fetch->method('fetch')->willReturn(['id'=>'r2','user_id'=>1,'points_earned'=>20,'status'=>'pending']);
        // update record status
        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($fetch, $update);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('PUT', '/carbon-track/transactions/r2/reject', ['action' => 'reject', 'review_note' => '资料不完整']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->reviewRecord($request, $response, ['id' => 'r2']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testGetRecordDetailAsAdmin(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['id'=>'r3','images'=>null]);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('GET', '/carbon-track/transactions/r3');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getRecordDetail($request, $response, ['id' => 'r3']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testGetUserRecordsPaged(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetch')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>'r1','images'=>null]
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('GET', '/carbon-track/records');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserRecords($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['pagination']['total']);
    }

    public function testSubmitRecordValidationMissingField(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1]);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('POST', '/carbon-track/record', ['activity_id' => 'a1', 'amount' => 1]); // missing date
        $response = new \Slim\Psr7\Response();
        $resp = $controller->submitRecord($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testGetRecordDetailForbiddenForOtherUser(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>2]);
        $auth->method('isAdminUser')->willReturn(false);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false); // not found when not owner
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('GET', '/carbon-track/transactions/r1');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getRecordDetail($request, $response, ['id' => 'r1']);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testReviewRecordApproveFlow(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        // fetch record
        $fetch = $this->createMock(\PDOStatement::class);
        $fetch->method('execute')->willReturn(true);
        $fetch->method('fetch')->willReturn(['id'=>'r1','user_id'=>1,'points_earned'=>20,'status'=>'pending']);
        // update record status
        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);
        // update user points
        $updatePoints = $this->createMock(\PDOStatement::class);
        $updatePoints->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($fetch, $update, $updatePoints);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('PUT', '/carbon-track/transactions/r1/approve', ['action' => 'approve']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->reviewRecord($request, $response, ['id' => 'r1']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testReviewRecordUnifiedStatusApproved(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        // fetch record
        $fetch = $this->createMock(\PDOStatement::class);
        $fetch->method('execute')->willReturn(true);
        $fetch->method('fetch')->willReturn(['id'=>'r9','user_id'=>1,'points_earned'=>30,'status'=>'pending']);
        // update record status
        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);
        // update user points
        $updatePoints = $this->createMock(\PDOStatement::class);
        $updatePoints->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($fetch, $update, $updatePoints);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('PUT', '/carbon-track/transactions/r9', ['status' => 'approved']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->reviewRecord($request, $response, ['id' => 'r9']);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
    }

    public function testDeleteTransactionForOwner(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>3]);
        $auth->method('isAdminUser')->willReturn(false);

        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($update);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('DELETE', '/carbon-track/transactions/r2');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->deleteTransaction($request, $response, ['id' => 'r2']);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testGetPendingRecordsRequiresAdmin(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1]);
        $auth->method('isAdmin')->willReturn(false);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('GET', '/admin/activities');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getPendingRecords($request, $response);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testGetPendingRecordsSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $calc = $this->createMock(CarbonCalculatorService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetch')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>'r1','images'=>null]
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt);

        $controller = new CarbonTrackController($pdo, $calc, $msg, $audit, $auth);
        $request = makeRequest('GET', '/admin/activities');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getPendingRecords($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['pagination']['total']);
    }
}


