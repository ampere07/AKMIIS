<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrder extends Model
{
    protected $table = 'job_orders';

    protected $fillable = [
        'application_id',
        'account_id',
        'status',
        'timestamp',
        'date_installed',
        'installation_fee',
        'billing_day',
        'billing_status',
        'modem_router_sn',
        'router_model',
        'group_name',
        'lcpnap',
        'port',
        'vlan',
        'username',
        'ip_address',
        'connection_type',
        'usage_type',
        'username_status',
        'visit_by',
        'visit_with',
        'visit_with_other',
        'onsite_status',
        'assigned_email',
        'status_remarks',
        'onsite_remarks',
        'status_remarks_id',
        'address_coordinates',
        'contract_link',
        'client_signature_url',
        'setup_image_url',
        'speedtest_image_url',
        'signed_contract_image_url',
        'box_reading_image_url',
        'router_reading_image_url',
        'port_label_image_url',
        'house_front_picture_url',
        'installation_landmark',
        'pppoe_username',
        'pppoe_password',
        'created_by_user_email',
        'updated_by_user_email',
        'start_time',
        'end_time',
        'proof_image_url',
    ];

    protected $dates = [
        'timestamp',
        'date_installed',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'installation_fee' => 'decimal:2',
        'billing_day' => 'integer',
        'timestamp' => 'datetime',
        'date_installed' => 'date',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class , 'application_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(JobOrderItem::class , 'job_order_id', 'id');
    }

    public function lcpnapLocation()
    {
        return $this->belongsTo(LCPNAPLocation::class , 'lcpnap', 'lcpnap_name');
    }
}
