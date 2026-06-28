<?php return array (
  'App\\Providers\\EventServiceProvider' => 
  array (
    'App\\Domain\\Master\\Customer\\Events\\CustomerCreated' => 
    array (
      0 => 'App\\Domain\\Master\\Customer\\Listeners\\SendWelcomeNotification',
    ),
    'App\\Domain\\Finance\\Tts\\Events\\TtsPosted' => 
    array (
      0 => 'App\\Domain\\Finance\\Kuitansi\\Listeners\\AutoIssueKuitansi',
    ),
    'App\\Domain\\Sales\\Booking\\Events\\BookingCreated' => 
    array (
      0 => 'App\\Domain\\Commission\\Listeners\\CalculateOnBookingCreated',
    ),
    'App\\Domain\\Approval\\Events\\ApprovalApproved' => 
    array (
      0 => 'App\\Domain\\Approval\\Listeners\\ApplyApprovedOutcome',
    ),
  ),
  'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider' => 
  array (
  ),
);