<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Omnichannel\UssdMenuEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class UssdController extends Controller
{
    public function __construct(private readonly UssdMenuEngine $menuEngine) {}

    /**
     * Handles inbound Africa's Talking USSD gateway webhook.
     */
    public function handleUssd(Request $request): Response
    {
        $data = [
            'sessionId'   => (string) $request->input('sessionId', ''),
            'serviceCode' => (string) $request->input('serviceCode', '*384#'),
            'phoneNumber' => (string) $request->input('phoneNumber', ''),
            'text'        => (string) $request->input('text', ''),
        ];

        $responseString = $this->menuEngine->handleUssdRequest($data);

        // Africa's Talking gateway expects raw text/plain with CON or END prefix
        return response($responseString, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }
}