<?php

namespace Tests\Unit;

use App\Services\AI\AiFailureClassifier;
use App\Support\AI\AiCreditEnforcementException;
use App\Support\AI\AiFailureCategory;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AiFailureClassifierTest extends TestCase
{
    public function test_classifies_enforcement_exception_as_insufficient_credits_regardless_of_message(): void
    {
        $this->assertSame(
            AiFailureCategory::INSUFFICIENT_CREDITS,
            AiFailureClassifier::classify(new AiCreditEnforcementException("This organisation's monthly AI usage allowance has been used."))
        );
    }

    public function test_classifies_missing_file_as_validation_failure(): void
    {
        $this->assertSame(
            AiFailureCategory::VALIDATION_FAILURE,
            AiFailureClassifier::classify(new RuntimeException('Contract file not found.'))
        );
    }

    public function test_classifies_unsupported_type_as_validation_failure(): void
    {
        $this->assertSame(
            AiFailureCategory::VALIDATION_FAILURE,
            AiFailureClassifier::classify(new RuntimeException("File type '.jpg' is not supported for AI analysis. Please upload a PDF, DOCX, or TXT file."))
        );
    }

    public function test_classifies_missing_configuration_as_internal_exception(): void
    {
        $this->assertSame(
            AiFailureCategory::INTERNAL_EXCEPTION,
            AiFailureClassifier::classify(new RuntimeException('AI analysis is not configured. Please contact your administrator.'))
        );
    }

    public function test_classifies_failed_provider_response_as_provider_rejection(): void
    {
        $this->assertSame(
            AiFailureCategory::PROVIDER_REJECTION,
            AiFailureClassifier::classify(new RuntimeException('The AI request could not be completed. Please try again later.'))
        );
    }

    public function test_classifies_truncated_output_as_output_truncated_not_provider_rejection(): void
    {
        $this->assertSame(
            AiFailureCategory::OUTPUT_TRUNCATED,
            AiFailureClassifier::classify(new RuntimeException(
                'The analysis was longer than the response limit and was cut off. The limit has been raised — please re-run.'
            ))
        );
    }

    public function test_classifies_unparseable_response_as_provider_rejection(): void
    {
        $this->assertSame(
            AiFailureCategory::PROVIDER_REJECTION,
            AiFailureClassifier::classify(new RuntimeException('AI returned a response that could not be read. You can re-parse the saved response without using more credits.'))
        );
    }

    public function test_classifies_connection_exception_with_timeout_message_as_timeout(): void
    {
        $this->assertSame(
            AiFailureCategory::TIMEOUT,
            AiFailureClassifier::classify(new ConnectionException('cURL error 28: Operation timed out after 420000 milliseconds'))
        );
    }

    public function test_classifies_other_connection_exception_as_transport_error(): void
    {
        $this->assertSame(
            AiFailureCategory::TRANSPORT_ERROR,
            AiFailureClassifier::classify(new ConnectionException('cURL error 6: Could not resolve host'))
        );
    }

    public function test_classifies_unrecognized_runtime_exception_as_unknown(): void
    {
        $this->assertSame(
            AiFailureCategory::UNKNOWN,
            AiFailureClassifier::classify(new RuntimeException('Something genuinely unanticipated.'))
        );
    }

    public function test_classifies_non_runtime_exception_as_internal_exception(): void
    {
        $this->assertSame(
            AiFailureCategory::INTERNAL_EXCEPTION,
            AiFailureClassifier::classify(new \TypeError('Argument #1 must be of type string.'))
        );
    }
}
