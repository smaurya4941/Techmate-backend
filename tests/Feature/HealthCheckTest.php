<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test the /api/health endpoint returns successful status and valid JSON.
     */
    public function test_health_check_returns_successful_response(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'application' => 'Techmate API',
                'message' => 'API is running successfully.',
            ])
            ->assertJsonStructure([
                'success',
                'application',
                'message',
                'environment',
            ]);
    }
}
