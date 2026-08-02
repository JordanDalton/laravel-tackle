<?php

namespace Tackle\Contracts;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;

interface CodingAgent extends Agent, Conversational, HasTools {}
