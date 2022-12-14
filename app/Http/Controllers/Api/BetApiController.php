<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceBetApiRequest;
use App\Models\Bet;
use App\Models\BetSelection;
use App\Models\Player;
use App\Services\OddsCalculator;
use App\Services\TransactionsManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;


class BetApiController extends Controller
{
    const MAX_WIN_AMOUNT = 20000;

    public function __construct(
        OddsCalculator      $oddsCalculator,
        TransactionsManager $transactionsManager,
    )
    {
        $this->OddsCalculator = $oddsCalculator;
        $this->TransactionsManager = $transactionsManager;
    }

    /**
     * @throws ValidationException
     */
    public function placeBet(PlaceBetApiRequest $request)
    {
        $playerBalance = Player::find($request->player_id)->balance;
        // checking player balance
        if (floatval($playerBalance) < floatval($request->stake_amount)) {
            return response()->json(['message' => ' not enough balance to make this bet']);
        }

        //count maximum winning amount
        $maxWinAmount = $this->OddsCalculator->count($request);

        if ($maxWinAmount > self::MAX_WIN_AMOUNT) {
            return response()->json([
                'errors' => 'Maximum win amount is '.self::MAX_WIN_AMOUNT]);
        }

        $selections = $request->selections;

        $stake = Bet::create(['stake_amount' => $request->stake_amount]);

        foreach ($selections as $selection) {
            //selection validation
            $validator = Validator::make($request->all(), [
                'selections.*.odds' => ['required', 'numeric', 'between:1,10000'],
                'selections.*.id' => 'distinct',
            ], ['selections.*.id.distinct' => 'Duplicate selection found']);

            if ($validator->fails()) {
                $errors = $validator->errors();
                $response = new Response([
                    'selection_error' => [
                        'id' => $selection['id'],
                        'errors' => $errors->first()
                    ]], 422);

                throw new ValidationException($validator, $response);
            }

            BetSelection::create([
                "selection_id" => $selection['id'],
                "bet_id" => $stake->id,
                "odds" => $selection['odds'],
            ]);
        }
        // deducted from player balance
        $this->TransactionsManager->make($request);

        return response()->json(['message' => 'Your bet is placed'], 201);
    }
}
