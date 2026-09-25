<?php
namespace App\Domain\Underwriting;
enum ProposalStatus:string { case Draft='DRAFT'; case DisclosuresPending='DISCLOSURES_PENDING'; case DocumentsPending='DOCUMENTS_PENDING'; case Submitted='SUBMITTED'; case UnderReview='UNDER_REVIEW'; case Approved='APPROVED'; case Counteroffered='COUNTEROFFERED'; case Declined='DECLINED'; case PaymentPending='PAYMENT_PENDING'; case InformationRequired='INFORMATION_REQUIRED'; case Resubmitted='RESUBMITTED'; case Withdrawn='WITHDRAWN'; }
