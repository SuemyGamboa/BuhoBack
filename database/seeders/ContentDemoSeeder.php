<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\LearningActivity;
use App\Models\Reward;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class ContentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $achievements = collect([
            ['name' => 'Detective de patrones', 'description' => 'Completó un reto de patrones.', 'icon_url' => null],
            ['name' => 'Memoria brillante', 'description' => 'Encontró todas las parejas.', 'icon_url' => null],
            ['name' => 'Rayo matemático', 'description' => 'Terminó un reto contra el tiempo.', 'icon_url' => null],
        ])->mapWithKeys(fn (array $achievement): array => [
            $achievement['name'] => Achievement::firstOrCreate(
                ['name' => $achievement['name']],
                ['description' => $achievement['description'], 'icon_url' => $achievement['icon_url']],
            )->getKey(),
        ]);

        $rewards = collect([
            ['name' => 'Sombrero de explorador', 'type' => 'avatar'],
            ['name' => 'Pegatina de estrella', 'type' => 'sticker'],
            ['name' => 'Capa estelar', 'type' => 'visual'],
        ])->mapWithKeys(fn (array $reward): array => [
            $reward['name'] => Reward::firstOrCreate(
                ['name' => $reward['name']],
                ['type' => $reward['type']],
            )->getKey(),
        ]);

        $subjects = [
            [
                'name' => 'Matemáticas de prueba',
                'description' => 'Retos sencillos de números, sumas y figuras.',
                'icon' => 'calculate',
                'color' => '#4d96ff',
                'activities' => [
                    [
                        'name' => 'Memorama de números',
                        'description' => 'Encuentra las parejas de números y sus nombres.',
                        'game_type' => 'memorama',
                        'instructions' => 'Voltea dos cartas y busca el número con su nombre.',
                        'content' => [
                            'time_limit' => 60,
                            'pairs' => [
                                ['id' => 'p1', 'content_a' => '1', 'content_b' => 'Uno', 'label' => 'Número uno'],
                                ['id' => 'p2', 'content_a' => '2', 'content_b' => 'Dos', 'label' => 'Número dos'],
                                ['id' => 'p3', 'content_a' => '3', 'content_b' => 'Tres', 'label' => 'Número tres'],
                            ],
                        ],
                        'badge_name' => 'Memoria brillante',
                    ],
                    [
                        'name' => 'Lleva cada figura a su lugar',
                        'description' => 'Relaciona cada objeto con su forma.',
                        'game_type' => 'drag_drop',
                        'instructions' => 'Elige un objeto y colócalo en la zona de su forma.',
                        'content' => [
                            'zones' => [
                                ['id' => 'z1', 'name' => 'Círculo', 'content' => '○'],
                                ['id' => 'z2', 'name' => 'Cuadrado', 'content' => '□'],
                            ],
                            'items' => [
                                ['id' => 'i1', 'content' => 'Pelota', 'correct_zone' => 'z1'],
                                ['id' => 'i2', 'content' => 'Ventana', 'correct_zone' => 'z2'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Cuenta las manzanas',
                        'description' => 'Cuenta objetos y elige la respuesta correcta.',
                        'game_type' => 'quiz',
                        'instructions' => 'Cuenta las manzanas y selecciona la respuesta.',
                        'content' => [
                            'questions' => [[
                                'id' => 'q1',
                                'question' => '¿Cuántas manzanas hay? 🍎 🍎 🍎',
                                'image_url' => null,
                                'options' => [
                                    ['id' => 'o1', 'content' => '2', 'is_correct' => false],
                                    ['id' => 'o2', 'content' => '3', 'is_correct' => true],
                                    ['id' => 'o3', 'content' => '4', 'is_correct' => false],
                                ],
                            ]],
                        ],
                    ],
                    [
                        'name' => 'Relaciona sumas y resultados',
                        'description' => 'Une cada suma sencilla con su resultado.',
                        'game_type' => 'matching',
                        'instructions' => 'Toca una suma y luego su resultado correcto.',
                        'content' => [
                            'pairs' => [
                                ['id' => 'm1', 'left' => '1 + 1', 'right' => '2'],
                                ['id' => 'm2', 'left' => '2 + 1', 'right' => '3'],
                                ['id' => 'm3', 'left' => '2 + 2', 'right' => '4'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Elige el número mayor',
                        'description' => 'Compara dos números pequeños.',
                        'game_type' => 'quiz',
                        'instructions' => 'Lee los números y elige el que es mayor.',
                        'content' => [
                            'questions' => [[
                                'id' => 'q1',
                                'question' => '¿Cuál número es mayor?',
                                'image_url' => null,
                                'options' => [
                                    ['id' => 'o1', 'content' => '4', 'is_correct' => false],
                                    ['id' => 'o2', 'content' => '7', 'is_correct' => true],
                                    ['id' => 'o3', 'content' => '2', 'is_correct' => false],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Español',
                'description' => 'Actividades sencillas para practicar letras, palabras y vocabulario.',
                'icon' => 'menu_book',
                'color' => '#ff8a65',
                'activities' => [
                    [
                        'name' => 'Memorama de vocales',
                        'description' => 'Encuentra cada vocal junto con su sonido.',
                        'game_type' => 'memorama',
                        'instructions' => 'Voltea las cartas y une cada vocal con su sonido.',
                        'content' => [
                            'pairs' => [
                                ['id' => 'p1', 'content_a' => 'A', 'content_b' => 'A de avión', 'label' => 'Vocal A'],
                                ['id' => 'p2', 'content_a' => 'E', 'content_b' => 'E de estrella', 'label' => 'Vocal E'],
                                ['id' => 'p3', 'content_a' => 'O', 'content_b' => 'O de oso', 'label' => 'Vocal O'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Une palabra e imagen',
                        'description' => 'Relaciona cada palabra con el objeto que representa.',
                        'game_type' => 'matching',
                        'instructions' => 'Toca la palabra y después su dibujo.',
                        'content' => [
                            'pairs' => [
                                ['id' => 'm1', 'left' => 'Sol', 'right' => '☀️'],
                                ['id' => 'm2', 'left' => 'Casa', 'right' => '🏠'],
                                ['id' => 'm3', 'left' => 'Gato', 'right' => '🐱'],
                            ],
                        ],
                    ],
                    [
                        'name' => '¿Con qué letra empieza?',
                        'description' => 'Reconoce el sonido inicial de una palabra.',
                        'game_type' => 'quiz',
                        'instructions' => 'Mira el dibujo y elige su primera letra.',
                        'content' => [
                            'questions' => [[
                                'id' => 'q1',
                                'question' => '¿Con qué letra empieza “mesa”?',
                                'image_url' => null,
                                'options' => [
                                    ['id' => 'o1', 'content' => 'M', 'is_correct' => true],
                                    ['id' => 'o2', 'content' => 'S', 'is_correct' => false],
                                    ['id' => 'o3', 'content' => 'P', 'is_correct' => false],
                                ],
                            ]],
                        ],
                    ],
                    [
                        'name' => 'Clasifica las palabras',
                        'description' => 'Separa nombres de animales y alimentos.',
                        'game_type' => 'drag_drop',
                        'instructions' => 'Coloca cada palabra en su categoría.',
                        'content' => [
                            'zones' => [
                                ['id' => 'z1', 'name' => 'Animales', 'content' => '🐾'],
                                ['id' => 'z2', 'name' => 'Alimentos', 'content' => '🍎'],
                            ],
                            'items' => [
                                ['id' => 'i1', 'content' => 'Perro', 'correct_zone' => 'z1'],
                                ['id' => 'i2', 'content' => 'Manzana', 'correct_zone' => 'z2'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Memorama de palabras',
                        'description' => 'Encuentra palabras sencillas y sus dibujos.',
                        'game_type' => 'memorama',
                        'instructions' => 'Voltea las cartas para unir cada palabra con su dibujo.',
                        'content' => [
                            'pairs' => [
                                ['id' => 'p1', 'content_a' => 'Luna', 'content_b' => '🌙', 'label' => 'Luna'],
                                ['id' => 'p2', 'content_a' => 'Flor', 'content_b' => '🌼', 'label' => 'Flor'],
                                ['id' => 'p3', 'content_a' => 'Pez', 'content_b' => '🐟', 'label' => 'Pez'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Inglés',
                'description' => 'Primeras palabras en inglés con juegos cortos y visuales.',
                'icon' => 'translate',
                'color' => '#9b6dff',
                'activities' => [
                    [
                        'name' => 'Memorama de saludos',
                        'description' => 'Encuentra saludos en inglés y español.',
                        'game_type' => 'memorama',
                        'instructions' => 'Une cada saludo en inglés con su significado.',
                        'content' => [
                            'pairs' => [
                                ['id' => 'p1', 'content_a' => 'Hello', 'content_b' => 'Hola', 'label' => 'Saludo'],
                                ['id' => 'p2', 'content_a' => 'Bye', 'content_b' => 'Adiós', 'label' => 'Despedida'],
                                ['id' => 'p3', 'content_a' => 'Thanks', 'content_b' => 'Gracias', 'label' => 'Agradecimiento'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Une el color',
                        'description' => 'Relaciona colores en inglés con su nombre en español.',
                        'game_type' => 'matching',
                        'instructions' => 'Toca el color en inglés y luego su traducción.',
                        'content' => [
                            'pairs' => [
                                ['id' => 'm1', 'left' => 'Red', 'right' => 'Rojo'],
                                ['id' => 'm2', 'left' => 'Blue', 'right' => 'Azul'],
                                ['id' => 'm3', 'left' => 'Green', 'right' => 'Verde'],
                            ],
                        ],
                    ],
                    [
                        'name' => '¿Qué animal es?',
                        'description' => 'Practica nombres de animales en inglés.',
                        'game_type' => 'quiz',
                        'instructions' => 'Mira el animal y elige su nombre en inglés.',
                        'content' => [
                            'questions' => [[
                                'id' => 'q1',
                                'question' => '¿Cómo se dice “gato” en inglés? 🐱',
                                'image_url' => null,
                                'options' => [
                                    ['id' => 'o1', 'content' => 'Cat', 'is_correct' => true],
                                    ['id' => 'o2', 'content' => 'Dog', 'is_correct' => false],
                                    ['id' => 'o3', 'content' => 'Bird', 'is_correct' => false],
                                ],
                            ]],
                        ],
                    ],
                    [
                        'name' => 'Separa los saludos',
                        'description' => 'Distingue saludos de despedidas en inglés.',
                        'game_type' => 'drag_drop',
                        'instructions' => 'Coloca cada palabra en saludos o despedidas.',
                        'content' => [
                            'zones' => [
                                ['id' => 'z1', 'name' => 'Saludos', 'content' => '👋'],
                                ['id' => 'z2', 'name' => 'Despedidas', 'content' => '🚪'],
                            ],
                            'items' => [
                                ['id' => 'i1', 'content' => 'Hello', 'correct_zone' => 'z1'],
                                ['id' => 'i2', 'content' => 'Goodbye', 'correct_zone' => 'z2'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Memorama de animales',
                        'description' => 'Encuentra animales y sus nombres en inglés.',
                        'game_type' => 'memorama',
                        'instructions' => 'Une cada animal con su nombre en inglés.',
                        'content' => [
                            'pairs' => [
                                ['id' => 'p1', 'content_a' => '🐶', 'content_b' => 'Dog', 'label' => 'Perro'],
                                ['id' => 'p2', 'content_a' => '🐱', 'content_b' => 'Cat', 'label' => 'Gato'],
                                ['id' => 'p3', 'content_a' => '🐟', 'content_b' => 'Fish', 'label' => 'Pez'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($subjects as $subjectOrder => $subjectData) {
            $subject = Subject::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($subjectData['name'])])
                ->first();

            if ($subject) {
                $subject->update([
                    ...collect($subjectData)->except('activities')->all(),
                    'is_active' => true,
                    'sort_order' => $subjectOrder,
                ]);
            } else {
                $subject = Subject::create([
                    ...collect($subjectData)->except('activities')->all(),
                    'is_active' => true,
                    'sort_order' => $subjectOrder,
                ]);
            }

            $activityNames = array_column($subjectData['activities'], 'name');
            LearningActivity::query()
                ->where('subject_id', $subject->getKey())
                ->whereIn('name', [
                    'Completa el patrón de estrellas',
                    'Elige la respuesta',
                    'Encuentra los números pares',
                    'Reto relámpago de sumas',
                    'Construye una torre de diez',
                    'Apunta al resultado correcto',
                    'Ordena las figuras por categoría',
                    'Suma las frutas',
                ])
                ->whereNotIn('name', $activityNames)
                ->update(['is_active' => false]);

            foreach ($subjectData['activities'] as $activityOrder => $activity) {
                LearningActivity::updateOrCreate(
                    [
                        'subject_id' => $subject->getKey(),
                        'name' => $activity['name'],
                    ],
                    [
                        ...$activity,
                        'config' => $activity['content'],
                        'achievement_id' => $achievements->get($activity['badge_name'] ?? ''),
                        'reward_item_id' => $activityOrder === 0
                            ? $rewards->get('Sombrero de explorador')
                            : null,
                        'unlock_after' => 0,
                        'reward_stars' => 1 + ($activityOrder % 3),
                        'reward_coins' => 10 + ($activityOrder * 5),
                        'sort_order' => $activityOrder + 1,
                        'difficulty' => 1,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
