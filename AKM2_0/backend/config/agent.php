<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent programme start date
    |--------------------------------------------------------------------------
    |
    | The day the agent programme begins counting. Referrals onboarded before
    | this date are history: they earn no incentive and add nothing to weekly or
    | monthly achievement progress.
    |
    | One value covers both, so an agent's incentive and their achievement
    | progress can never disagree about which of their referrals count. The
    | dashboards read the same date from their own shared copy — see
    | AGENT_JOB_ORDER_START_DATE in the frontends' agentReferral helpers — so
    | the Job Order list shows exactly the referrals these figures are built on.
    |
    | Set to null to count an agent's whole history, as before this was
    | introduced.
    |
    */

    'start_date' => env('AGENT_START_DATE', '2026-08-10'),

];
