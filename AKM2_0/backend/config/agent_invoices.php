<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Weekly agent referral invoices
    |--------------------------------------------------------------------------
    */

    // Prefix for the invoice number: AKM-AGT-000001.
    'number_prefix' => env('AGENT_INVOICE_PREFIX', 'AKM-AGT'),

    // How many digits the running number is padded to.
    'number_padding' => 6,

    /*
    | What a referral is worth on the invoice.
    |
    | Falls back to the agent's own configured incentive value where one is set,
    | so an agent on a different rate is billed at their rate rather than this
    | one. This is the default when they have none.
    */
    'unit_price' => env('AGENT_INVOICE_UNIT_PRICE', 100),

    /*
    | The installation fee stated on the invoice. It is not derivable from a
    | referral count — the reference document states it rather than calculating
    | it — and it does not form part of what is owed.
    |
    | COMMISSION is NOT configured here. It is earned per referral at the rate
    | on the referring agent's own record (agent_balance.commission), so the
    | invoice total is the sum across its customers. A team on one rate bills
    | customers x rate; a team on mixed rates bills each customer at theirs.
    |
    | SUBTOTAL is TOTAL AMOUNT + COMMISSION.
    */
    'installation_fee' => env('AGENT_INVOICE_INSTALLATION_FEE', 500),

    // Where the generated PDFs live, under storage/app/public.
    'pdf_folder' => 'agent-invoices',

    /*
    | Only referrals installed on or after the agent programme start date are
    | ever billed. Shared with incentives and achievements so all three agree
    | about which referrals count — see config/agent.php.
    */

    // How many agents/teams to hold in memory at once during a run.
    'chunk_size' => 200,

];
