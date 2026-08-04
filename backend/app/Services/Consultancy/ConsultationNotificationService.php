<?php

namespace App\Services\Consultancy;

use App\Models\ConsultationEnquiry;
use App\Models\SuresignSetting;
use App\Services\EmailNotificationService;

/**
 * Deliberately kept out of `AppointmentEmailService` (Appointments-owned)
 * per this phase's Core Principle. Mirrors `AppointmentEmailService`'s own
 * shape: a plain service composing subject/body text and calling the
 * existing `EmailNotificationService::sendDirect()` — the attendee is
 * external, not an organisation's in-app `User` necessarily (a public
 * booking has no `User` record at all), so `NotificationService::send()`
 * (in-app, requires a real `User`) doesn't fit here.
 *
 * Batch 3 (Consultancy Communications & Global Email Experience Upgrade)
 * moved this service's other method, `sendSummaryPublishedNotice()`, over
 * to `App\Services\Consultancy\ConsultationCommunicationService::sendSummaryPublished()` —
 * that batch's audit found the version here was plain-text, carried no
 * link at all, and told a public no-account customer their summary was
 * "available in your SureSign account," which is simply false for them.
 * This service now owns only `awaiting_customer` — an internal "action
 * needed" notice, out of that batch's public-customer-journey scope.
 *
 * Always dispatched via `SendConsultationEmailJob` (queued,
 * `->afterCommit()`) by the caller — never called synchronously from a
 * request, mirroring `SendAppointmentEmailJob`'s own contract.
 */
class ConsultationNotificationService
{
    /**
     * Sent exactly once per genuine transition into 'awaiting_customer' —
     * the caller (ConsultancyOperationsController::markAwaitingCustomer())
     * only reaches this after EngagementLifecycleService::transitionManual()
     * has already succeeded, and that method throws for a repeated/invalid
     * transition before this is ever called. Not a messaging feature — no
     * reply mechanism, just a plain notice that action is needed.
     */
    public function sendAwaitingCustomerNotice(ConsultationEnquiry $enquiry): bool
    {
        $appointment = $enquiry->appointment;

        $subject = "Action needed on your consultation — {$appointment->reference}";
        $body = "Hi {$appointment->attendee_name},\n\n"
            ."Your consultant has requested additional information before your consultation ({$appointment->reference}) can continue.\n\n"
            .$this->contactLine();

        return EmailNotificationService::sendDirect($appointment->attendee_email, $subject, $body, category: 'Consultancy');
    }

    /**
     * Mirrors AppointmentEmailService::contactLine() exactly — kept as a
     * second, small copy rather than extracting a shared trait/base class
     * for two one-line methods across two otherwise-unrelated services.
     */
    private function contactLine(): string
    {
        $email = SuresignSetting::instance()->support_email;

        return $email ? "Please contact us at {$email} if you have any questions." : 'Please get in touch if you have any questions.';
    }
}
