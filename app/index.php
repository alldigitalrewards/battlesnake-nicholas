<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Middleware\BodyParsingMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$app->get('/', function (Request $request, Response $response) {
    $data = [
        'apiversion' => '1',
        'author' => 'DoctorPoop',
        'color' => '#573527',
        'head' => 'default',
        'tail' => 'default'
    ];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/start', function (Request $request, Response $response) {
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/move', function (Request $request, Response $response) {
    $data = $request->getParsedBody();
    $you = $data['you'] ?? [];
    $board = $data['board'] ?? [];
    $head = $you['body'][0] ?? null;
    $health = $you['health'];

    $width = $board['width'] ?? 11;
    $height = $board['height'] ?? 11;
    $food = $board['food'] ?? [];

    $possibleMoves = ['up', 'down', 'left', 'right'];
    $safeMoves = [];

    foreach ($possibleMoves as $move) {
        $newHead = getNewHeadPosition($head, $move);

        $collision = false;
        $tail = end($you['body']);
        $willEat = false;
        foreach ($food as $f) {
            if ($f['x'] === $newHead['x'] && $f['y'] === $newHead['y']) {
                $willEat = true;
                break;
            }
        }

        $nextBody = $you['body'];
        if (!$willEat) {
            array_pop($nextBody);
        }

        foreach ($nextBody as $segment) {
            if ($segment['x'] === $newHead['x'] && $segment['y'] === $newHead['y']) {
                $collision = true;
                break;
            }
        }

        foreach ($board['snakes'] as $snake) {
            foreach ($snake['body'] as $segment) {
                if ($segment['x'] === $newHead['x'] && $segment['y'] === $newHead['y']) {
                    $collision = true;
                    break 2;
                }
            }
        }

        if (
            !$collision &&
            $newHead['x'] >= 0 && $newHead['x'] < $width &&
            $newHead['y'] >= 0 && $newHead['y'] < $height
        ) {
            $safeMoves[] = [
                'move' => $move,
                'position' => $newHead
            ];
        }
    }

    $length = count($you['body']);
    $aggressive = $health > 40 && $length >= 8;

    $best = null;
    foreach ($safeMoves as &$option) {
        $position = $option['position'];
        $option['score'] = 0;

        $space = floodFill($position, $board, $you);
        if ($space < 3) continue;
        $option['score'] += $space;

        foreach ($board['snakes'] as $snake) {
            if ($snake['id'] === $you['id']) continue;
            $enemyHead = $snake['body'][0];
            $enemyLength = count($snake['body']);
            $myLength = count($you['body']);

            $enemyMoves = [
                ['x' => $enemyHead['x'] + 1, 'y' => $enemyHead['y']],
                ['x' => $enemyHead['x'] - 1, 'y' => $enemyHead['y']],
                ['x' => $enemyHead['x'],     'y' => $enemyHead['y'] + 1],
                ['x' => $enemyHead['x'],     'y' => $enemyHead['y'] - 1]
            ];

            foreach ($enemyMoves as $em) {
                if ($em['x'] === $position['x'] && $em['y'] === $position['y']) {
                    if ($enemyLength >= $myLength) continue 2;
                }
            }

            $dist = abs($position['x'] - $enemyHead['x']) + abs($position['y'] - $enemyHead['y']);
            $option['score'] += max(0, 12 - $dist) * 4;

            $adjacent = [
                ['x' => $enemyHead['x'] + 1, 'y' => $enemyHead['y']],
                ['x' => $enemyHead['x'] - 1, 'y' => $enemyHead['y']],
                ['x' => $enemyHead['x'],     'y' => $enemyHead['y'] + 1],
                ['x' => $enemyHead['x'],     'y' => $enemyHead['y'] - 1]
            ];

            foreach ($adjacent as $adj) {
                if ($adj['x'] === $position['x'] && $adj['y'] === $position['y']) {
                    $option['score'] += 10;
                }
                foreach ([
                             ['x' => $adj['x'] + 1, 'y' => $adj['y']],
                             ['x' => $adj['x'] - 1, 'y' => $adj['y']],
                             ['x' => $adj['x'],     'y' => $adj['y'] + 1],
                             ['x' => $adj['x'],     'y' => $adj['y'] - 1],
                         ] as $future) {
                    if ($future['x'] === $position['x'] && $future['y'] === $position['y']) {
                        $option['score'] += 5;
                    }
                }
            }
        }

        $tail = end($you['body']);
        $tailDist = abs($position['x'] - $tail['x']) + abs($position['y'] - $tail['y']);
        if ($tailDist > 5) {
            $option['score'] -= 3;
        }

        if (!empty($food)) {
            $closestFood = null;
            $shortestDistance = PHP_INT_MAX;
            foreach ($food as $f) {
                $distance = abs($f['x'] - $head['x']) + abs($f['y'] - $head['y']);
                if ($distance < $shortestDistance) {
                    $shortestDistance = $distance;
                    $closestFood = $f;
                }
            }
            $dx = abs($position['x'] - $closestFood['x']);
            $dy = abs($position['y'] - $closestFood['y']);
            $dist = $dx + $dy;
            $option['score'] -= $dist * 2;
            if ($length < 8 || $health < 40) {
                $option['score'] -= 20;
            }
        }

        if (!isset($best) || $option['score'] > $best['score']) {
            $best = $option;
        }
    }

    $bestMove = $best['move'] ?? 'up';

    $response->getBody()->write(json_encode(['move' => $bestMove]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/end', function (Request $request, Response $response) {
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

function getNewHeadPosition(array $head, string $move): array
{
    return match ($move) {
        'up' => ['x' => $head['x'], 'y' => $head['y'] + 1],
        'down' => ['x' => $head['x'], 'y' => $head['y'] - 1],
        'left' => ['x' => $head['x'] - 1, 'y' => $head['y']],
        'right' => ['x' => $head['x'] + 1, 'y' => $head['y']],
        default => $head,
    };
}

function floodFill(array $start, array $board, array $you): int {
    $width = $board['width'];
    $height = $board['height'];
    $visited = [];
    $queue = [$start];
    $count = 0;

    $occupied = [];
    foreach ($board['snakes'] as $snake) {
        foreach ($snake['body'] as $segment) {
            $occupied[$segment['x'] . ',' . $segment['y']] = true;
        }
    }

    while (!empty($queue)) {
        $current = array_shift($queue);
        $key = $current['x'] . ',' . $current['y'];

        if (
            $current['x'] < 0 || $current['x'] >= $width ||
            $current['y'] < 0 || $current['y'] >= $height ||
            isset($visited[$key]) ||
            isset($occupied[$key])
        ) {
            continue;
        }

        $visited[$key] = true;
        $count++;

        $queue[] = ['x' => $current['x'] + 1, 'y' => $current['y']];
        $queue[] = ['x' => $current['x'] - 1, 'y' => $current['y']];
        $queue[] = ['x' => $current['x'],     'y' => $current['y'] + 1];
        $queue[] = ['x' => $current['x'],     'y' => $current['y'] - 1];
    }

    return $count;
}