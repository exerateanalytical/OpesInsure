<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Notifications\LifecycleNotificationProducer;
use App\Models\Claim;
use App\Models\PaymentIntentRecord;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\UnderwritingCase;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\ServiceProvider;

/** Wave 16 lifecycle wiring: customer notification producers (A14). */
final class LifecycleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $producer = fn (): LifecycleNotificationProducer => $this->app->make(LifecycleNotificationProducer::class);

        Quote::updated(fn (Quote $m) => $producer()->quoteUpdated($m));
        Proposal::updated(fn (Proposal $m) => $producer()->proposalUpdated($m));
        UnderwritingCase::updated(fn (UnderwritingCase $m) => $producer()->underwritingCaseUpdated($m));
        Claim::saved(fn (Claim $m) => $producer()->claimSaved($m));
        PaymentIntentRecord::updated(fn (PaymentIntentRecord $m) => $producer()->paymentUpdated($m));
        UserDevice::created(fn (UserDevice $m) => $producer()->deviceCreated($m));
        User::updated(fn (User $m) => $producer()->userUpdated($m));
    }
}
