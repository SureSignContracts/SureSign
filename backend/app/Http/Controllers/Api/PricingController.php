<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CopyPricingPlanRequest;
use App\Http\Requests\ReorderPricingRequest;
use App\Http\Requests\StorePricingFaqRequest;
use App\Http\Requests\StorePricingFeatureRequest;
use App\Http\Requests\StorePricingFeatureSectionRequest;
use App\Http\Requests\StorePricingIncludedItemRequest;
use App\Http\Requests\StorePricingPlanRequest;
use App\Http\Requests\UpdatePricingFaqRequest;
use App\Http\Requests\UpdatePricingFeatureRequest;
use App\Http\Requests\UpdatePricingFeatureSectionRequest;
use App\Http\Requests\UpdatePricingIncludedItemRequest;
use App\Http\Requests\UpdatePricingMatrixRequest;
use App\Http\Requests\UpdatePricingPlanEntitlementsRequest;
use App\Http\Requests\UpdatePricingPlanRequest;
use App\Http\Requests\UpdatePricingSettingsRequest;
use App\Models\PricingFaq;
use App\Models\PricingFeature;
use App\Models\PricingFeatureSection;
use App\Models\PricingIncludedItem;
use App\Models\PricingPlan;
use App\Models\PricingPlanFeature;
use App\Models\PricingSetting;
use App\Services\Pricing\PricingManagementService;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(private readonly PricingManagementService $pricing)
    {
    }

    // ─── Public ─────────────────────────────────────────────────────────────

    public function publicShow()
    {
        return response()->json(['data' => $this->pricing->publicPayload()]);
    }

    // ─── Settings ───────────────────────────────────────────────────────────

    public function showSettings()
    {
        return response()->json(['data' => PricingSetting::instance()]);
    }

    public function updateSettings(UpdatePricingSettingsRequest $request)
    {
        $settings = $this->pricing->updateSettings($request->validated(), $request->user());
        return response()->json(['data' => $settings]);
    }

    // ─── Plans ──────────────────────────────────────────────────────────────

    public function indexPlans()
    {
        // providerPrices eager-loaded so the admin plan editor can show a
        // read-only Stripe mapping summary — no Stripe mutation happens here.
        return response()->json(['data' => PricingPlan::orderBy('order')->with('providerPrices')->get()]);
    }

    public function storePlan(StorePricingPlanRequest $request)
    {
        $plan = $this->pricing->createPlan($request->validated(), $request->user());
        return response()->json(['data' => $plan], 201);
    }

    public function updatePlan(UpdatePricingPlanRequest $request, PricingPlan $plan)
    {
        $plan = $this->pricing->updatePlan($plan, $request->validated(), $request->user());
        return response()->json(['data' => $plan]);
    }

    public function publishPlan(Request $request, PricingPlan $plan)
    {
        $plan = $this->pricing->publishPlan($plan, $request->user());
        return response()->json(['data' => $plan]);
    }

    public function archivePlan(Request $request, PricingPlan $plan)
    {
        $plan = $this->pricing->archivePlan($plan, $request->user());
        return response()->json(['data' => $plan]);
    }

    public function destroyPlan(Request $request, PricingPlan $plan)
    {
        $deleted = $this->pricing->deleteOrArchivePlan($plan, $request->user());

        if (! $deleted) {
            return response()->json([
                'message' => 'This plan has been published or compared before, so it was archived instead of deleted.',
                'data'    => $plan->fresh(),
            ]);
        }

        return response()->json(['message' => 'Plan deleted.']);
    }

    public function reorderPlans(ReorderPricingRequest $request)
    {
        $this->pricing->reorderPlans($request->validated('order'), $request->user());
        return response()->json(['data' => PricingPlan::orderBy('order')->get()]);
    }

    public function copyPlan(CopyPricingPlanRequest $request, PricingPlan $plan)
    {
        $copy = $this->pricing->copyPlan($plan, $request->validated(), $request->user());
        return response()->json(['data' => $copy], 201);
    }

    // ─── Plan entitlement defaults (Phase G2) ───────────────────────────────

    public function showEntitlements(PricingPlan $plan)
    {
        return response()->json(['data' => $this->pricing->entitlementsForPlan($plan)]);
    }

    public function updateEntitlements(UpdatePricingPlanEntitlementsRequest $request, PricingPlan $plan)
    {
        $data = $this->pricing->updateEntitlements($plan, $request->validated('entitlements'), $request->user());
        return response()->json(['data' => $data]);
    }

    // ─── Feature sections ───────────────────────────────────────────────────

    public function indexFeatureSections()
    {
        return response()->json(['data' => PricingFeatureSection::with('features')->orderBy('order')->get()]);
    }

    public function storeFeatureSection(StorePricingFeatureSectionRequest $request)
    {
        $section = $this->pricing->createFeatureSection($request->validated(), $request->user());
        return response()->json(['data' => $section], 201);
    }

    public function updateFeatureSection(UpdatePricingFeatureSectionRequest $request, PricingFeatureSection $section)
    {
        $section = $this->pricing->updateFeatureSection($section, $request->validated(), $request->user());
        return response()->json(['data' => $section]);
    }

    public function destroyFeatureSection(Request $request, PricingFeatureSection $section)
    {
        $this->pricing->deleteFeatureSection($section, $request->user());
        return response()->json(['message' => 'Feature section deleted.']);
    }

    public function reorderFeatureSections(ReorderPricingRequest $request)
    {
        $this->pricing->reorderFeatureSections($request->validated('order'), $request->user());
        return response()->json(['data' => PricingFeatureSection::orderBy('order')->get()]);
    }

    // ─── Features ───────────────────────────────────────────────────────────

    public function indexFeatures()
    {
        return response()->json(['data' => PricingFeature::orderBy('order')->get()]);
    }

    public function storeFeature(StorePricingFeatureRequest $request)
    {
        $feature = $this->pricing->createFeature($request->validated(), $request->user());
        return response()->json(['data' => $feature], 201);
    }

    public function updateFeature(UpdatePricingFeatureRequest $request, PricingFeature $feature)
    {
        $feature = $this->pricing->updateFeature($feature, $request->validated(), $request->user());
        return response()->json(['data' => $feature]);
    }

    public function destroyFeature(Request $request, PricingFeature $feature)
    {
        $this->pricing->deleteFeature($feature, $request->user());
        return response()->json(['message' => 'Feature deleted.']);
    }

    public function reorderFeatures(ReorderPricingRequest $request)
    {
        $this->pricing->reorderFeatures($request->validated('order'), $request->user());
        return response()->json(['data' => PricingFeature::orderBy('order')->get()]);
    }

    // ─── Comparison matrix ──────────────────────────────────────────────────

    public function indexMatrix()
    {
        return response()->json(['data' => PricingPlanFeature::all()]);
    }

    public function updateMatrix(UpdatePricingMatrixRequest $request)
    {
        $this->pricing->updateMatrix($request->validated('updates'), $request->user());
        return response()->json(['message' => 'Comparison matrix updated.']);
    }

    // ─── FAQs ───────────────────────────────────────────────────────────────

    public function indexFaqs()
    {
        return response()->json(['data' => PricingFaq::orderBy('order')->get()]);
    }

    public function storeFaq(StorePricingFaqRequest $request)
    {
        $faq = $this->pricing->createFaq($request->validated(), $request->user());
        return response()->json(['data' => $faq], 201);
    }

    public function updateFaq(UpdatePricingFaqRequest $request, PricingFaq $faq)
    {
        $faq = $this->pricing->updateFaq($faq, $request->validated(), $request->user());
        return response()->json(['data' => $faq]);
    }

    public function destroyFaq(Request $request, PricingFaq $faq)
    {
        $this->pricing->deleteFaq($faq, $request->user());
        return response()->json(['message' => 'FAQ deleted.']);
    }

    public function reorderFaqs(ReorderPricingRequest $request)
    {
        $this->pricing->reorderFaqs($request->validated('order'), $request->user());
        return response()->json(['data' => PricingFaq::orderBy('order')->get()]);
    }

    // ─── Included items ─────────────────────────────────────────────────────

    public function indexIncludedItems()
    {
        return response()->json(['data' => PricingIncludedItem::orderBy('order')->get()]);
    }

    public function storeIncludedItem(StorePricingIncludedItemRequest $request)
    {
        $item = $this->pricing->createIncludedItem($request->validated(), $request->user());
        return response()->json(['data' => $item], 201);
    }

    public function updateIncludedItem(UpdatePricingIncludedItemRequest $request, PricingIncludedItem $item)
    {
        $item = $this->pricing->updateIncludedItem($item, $request->validated(), $request->user());
        return response()->json(['data' => $item]);
    }

    public function destroyIncludedItem(Request $request, PricingIncludedItem $item)
    {
        $this->pricing->deleteIncludedItem($item, $request->user());
        return response()->json(['message' => 'Included item deleted.']);
    }

    public function reorderIncludedItems(ReorderPricingRequest $request)
    {
        $this->pricing->reorderIncludedItems($request->validated('order'), $request->user());
        return response()->json(['data' => PricingIncludedItem::orderBy('order')->get()]);
    }
}
