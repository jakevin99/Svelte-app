<script lang="ts">
    /**
     * Memory Game Main Component
     * This is the core game component that handles:
     * - Game state management and logic
     * - User authentication (login/register)
     * - Score tracking and leaderboard
     * - Card matching mechanics
     */

    //C:\xampp\htdocs\php-matching-api\matching_game\src\routes\+page.svelte
	import { emoji } from './emoji'
	import { onDestroy, onMount } from 'svelte';

	/**
	 * Game State Type Definition
	 * - start: Initial game state
	 * - playing: Active gameplay
	 * - paused: Game temporarily stopped
	 * - won: Player successfully matched all cards
	 * - lost: Time ran out before matching all cards
	 */
	type State = 'start' | 'playing' | 'paused' | 'won' | 'lost'

	// Core game state
	let state: State = 'start'           // Current game state
	let size = 20                        // Number of cards in the game
	let grid = createGrid()              // Array of emoji cards
	let maxMatches = grid.length / 2     // Number of pairs needed to win
	let selected: number[] = []          // Currently selected card indices
	let matches: string[] = []           // Successfully matched emoji pairs
	let timerId: number | null = null    // Timer interval ID
	let time = 60                        // Game countdown timer in seconds

	// Authentication state
	let username = '';
	let password = '';
	let userId: number | null = null;     // Logged in user's ID
	let loginError = '';                  // Authentication error messages

	// Leaderboard state
	let leaderboard: any[] = [];          // Array of top scores
	let leaderboardTimer: number | null = null;
	let isSavingScore = false;            // Prevents duplicate score saves

	const API_BASE = 'http://localhost/php-matching-api/api';

	/**
	 * Creates a randomized grid of emoji pairs
	 * @returns string[] Array of emoji characters
	 */
	function createGrid() {
		let cards = new Set<string>()
		let maxSize = size / 2

		while (cards.size < maxSize) {
			const randomIndex = Math.floor(Math.random() * emoji.length)
			cards.add(emoji[randomIndex])
		}

		return shuffle([...cards, ...cards])
	}

	function shuffle<Items>(array: Items[]) {
		return array.sort(() => Math.random() - 0.5)
	}

	function startGameTimer() {
		async function countdown() {
			if (state === 'playing') {
				time -= 1;
				if (time <= 0) {
					if (timerId) {
						clearInterval(timerId);
						timerId = null;
					}
					await gameLost();
				}
			}
		}
		timerId = setInterval(countdown, 1000);
	}

	function selectCard(cardIndex: number) {
		selected = selected.concat(cardIndex)
	}

	function matchCards() {
		const [first, second] = selected

		if (grid[first] === grid[second]) {
			matches = matches.concat(grid[first])
		}

		// Increased delay from 300ms to 800ms to give more time to see the cards
		setTimeout(() => (selected = []), 800)
	}

	/**
	 * Handles game pause/resume functionality
	 * Triggered by Escape key press
	 * @param e Keyboard event object
	 */
	function pauseGame(e: KeyboardEvent) {
		if (e.key === 'Escape') {
			switch (state) {
				case 'playing':
					state = 'paused'
					break
				case 'paused':
					state = 'playing'
					break
			}
		}
	}

	/**
	 * Resets all game state variables to initial values
	 * Called when starting a new game or after game over
	 */
	function resetGame() {
		timerId && clearInterval(timerId)
		grid = createGrid()
		maxMatches = grid.length / 2
		selected = []
		matches = []
		timerId = null
		time = 60
		isSavingScore = false
	}

	/**
	 * Authentication Functions
	 */

	/**
	 * Handles user login
	 * Validates credentials and updates game state on success
	 * @returns Promise<void>
	 */
	async function login() {
		// Input validation
		if (!username.trim() || !password.trim()) {
			loginError = 'Username and password are required';
			return;
		}

		try {
			const response = await fetch(`${API_BASE}/login`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ username, password })
			});
			
			const data = await response.json();
			
			if (data.success) {
				userId = data.userId;
				state = 'start';
				loginError = '';
				await fetchLeaderboard(); // Initial fetch only on login
			} else {
				loginError = data.message || 'Invalid credentials';
			}
		} catch (error) {
			console.error('Login error:', error);
			loginError = 'Login failed. Please try again.';
		}
	}

	/**
	 * Handles new user registration
	 * Validates input and creates new user account
	 * @returns Promise<void>
	 */
	async function register() {
		// Input validation
		if (!username.trim() || !password.trim()) {
			loginError = 'Username and password are required';
			return;
		}

		// Minimum length requirements
		if (username.length < 3) {
			loginError = 'Username must be at least 3 characters long';
			return;
		}

		if (password.length < 6) {
			loginError = 'Password must be at least 6 characters long';
			return;
		}

		try {
			const response = await fetch(`${API_BASE}/register`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ username, password })
			});
			
			const data = await response.json();
			
			if (data.success) {
				await login(); // Only attempt login if registration was successful
			} else {
				loginError = data.message || 'Registration failed';
			}
		} catch (error) {
			console.error('Registration error:', error);
			loginError = 'Registration failed. Please try again.';
		}
	}

	/**
	 * Score and Leaderboard Functions
	 */

	/**
	 * Saves current game score to database
	 * @returns Promise<void>
	 */
	async function saveScore() {
		await fetch(`${API_BASE}/save-score`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				userId,
				score: matches.length,
				timeRemaining: time
			})
		});
	}

	/**
	 * Retrieves and updates leaderboard data
	 * @returns Promise<void>
	 */
	async function fetchLeaderboard() {
		try {
			const response = await fetch(`${API_BASE}/leaderboard`);
			const data = await response.json();
			leaderboard = data;
		} catch (error) {
			console.error('Error fetching leaderboard:', error);
		}
	}

	/**
	 * Cleans up leaderboard refresh timer
	 */
	function stopLeaderboardRefresh() {
		if (leaderboardTimer) {
			clearInterval(leaderboardTimer);
			leaderboardTimer = null;
		}
	}

	/**
	 * Game State Change Handlers
	 */

	/**
	 * Handles successful game completion
	 * Saves score and updates game state
	 * @returns Promise<void>
	 */
	async function gameWon() {
		if (state !== 'playing') return;
		
		if (timerId) {
			clearInterval(timerId);
			timerId = null;
		}

		if (userId) {
			try {
				await fetch(`${API_BASE}/save-score`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						userId,
						score: maxMatches,
						timeRemaining: time
					})
				});
				await fetchLeaderboard(); // Fetch only after saving score
			} catch (error) {
				console.error('Error saving score:', error);
			}
		}
		
		state = 'won';
		resetGame();
	}

	/**
	 * Handles game over condition
	 * Saves final score and updates game state
	 * @returns Promise<void>
	 */
	async function gameLost() {
		if (state !== 'playing' || isSavingScore) return;
		
		isSavingScore = true;
		
		if (timerId) {
			clearInterval(timerId);
			timerId = null;
		}

		// Save the score before resetting
		if (userId) {
			try {
				await fetch(`${API_BASE}/save-score`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						userId,
						score: matches.length,
						timeRemaining: 0
					})
				});
				await fetchLeaderboard();
			} catch (error) {
				console.error('Error saving score:', error);
			}
		}

		isSavingScore = false;
		state = 'lost';
		resetGame();
	}

	/**
	 * Returns to main menu from game
	 */
	function quitGame() {
		state = 'start';
		resetGame();
	}

	/**
	 * Handles user logout
	 * Clears all user-related state and returns to login screen
	 */
	function logout() {
		userId = null;
		username = '';
		password = '';
		state = 'start';
		stopLeaderboardRefresh();
		resetGame();
	}

	/**
	 * Reactive Declarations
	 * These automatically run when their dependencies change
	 */
	$: if (state === 'playing') {
		!timerId && startGameTimer()  // Start timer when game begins
	}

	$: selected.length === 2 && matchCards()  // Check for matches when 2 cards selected
	$: maxMatches === matches.length && gameWon()  // Trigger win when all pairs found
	$: time === 0 && gameLost()  // Trigger loss when time runs out

	/**
	 * Component Cleanup
	 * Ensures timers are cleared when component is destroyed
	 */
	onDestroy(() => {
		timerId && clearInterval(timerId);
	});

	// Enhanced animation functions
	let sphereAnimation: Animation | null = null;
	let particleAnimations: Animation[] = [];

	function createGalaxyBackground(container: HTMLElement) {
		for (let i = 0; i < 200; i++) {
			const star = document.createElement('div');
			star.className = 'star';
			star.style.left = `${Math.random() * 100}%`;
			star.style.top = `${Math.random() * 100}%`;
			star.style.animationDelay = `${Math.random() * 3}s`;
			container.appendChild(star);
		}
	}

	function createEnergyField(sphere: HTMLElement) {
		const energyParticles = 50;
		for (let i = 0; i < energyParticles; i++) {
			const particle = document.createElement('div');
			particle.className = 'energy-particle';
			
			const angle = (Math.PI * 2 * i) / energyParticles;
			const radius = 70 + Math.random() * 20;
			
			const x = Math.cos(angle) * radius;
			const y = Math.sin(angle) * radius;
			
			particle.style.left = `${x}px`;
			particle.style.top = `${y}px`;
			
			sphere.appendChild(particle);
			
			const anim = particle.animate([
				{ transform: 'scale(0) rotate(0deg)', opacity: 0 },
				{ transform: 'scale(1) rotate(180deg)', opacity: 0.8, offset: 0.5 },
				{ transform: 'scale(0) rotate(360deg)', opacity: 0 }
			], {
				duration: 3000 + Math.random() * 2000,
				iterations: Infinity,
				delay: Math.random() * -3000
			});
			
			particleAnimations.push(anim);
		}
	}

	function createSpherePoints(sphere: HTMLDivElement) {
		// Clear existing elements
		sphere.innerHTML = '';
		
		// Create structured points
		const longitudes = 24;
		const dotsPerLongitude = 24;
		const dots: HTMLDivElement[] = [];

		// Create longitude lines
		for (let long = 0; long < longitudes; long++) {
			const theta = (2 * Math.PI * long) / longitudes;
			
			for (let i = 0; i <= dotsPerLongitude; i++) {
				const phi = (Math.PI * i) / dotsPerLongitude;
				const x = Math.sin(phi) * Math.cos(theta);
				const y = Math.cos(phi);
				const z = Math.sin(phi) * Math.sin(theta);

				const dot = document.createElement('div');
				dot.className = 'dot';

				const scale = 60;
				const originalX = x * scale;
				const originalY = y * scale;
				const originalZ = z * scale;

				const explosionScale = 120;
				const explosionX = x * explosionScale;
				const explosionY = y * explosionScale;
				const explosionZ = z * explosionScale;

				dot.style.transform = `translate3d(${originalX}px, ${originalY}px, ${originalZ}px)`;

				const anim = dot.animate([
					{ 
						transform: `translate3d(${originalX}px, ${originalY}px, ${originalZ}px)`,
						opacity: 1,
						scale: 1
					},
					{ 
						transform: `translate3d(${explosionX}px, ${explosionY}px, ${explosionZ}px)`,
						opacity: 0.7,
						scale: 1.5,
						offset: 0.5 
					},
					{ 
						transform: `translate3d(${originalX}px, ${originalY}px, ${originalZ}px)`,
						opacity: 1,
						scale: 1
					}
				], {
					duration: 4000,
					iterations: Infinity,
					delay: (long * dotsPerLongitude + i) * -50,
					easing: 'ease-in-out'
				});

				particleAnimations.push(anim);
				sphere.appendChild(dot);
				dots.push(dot);
			}
		}

		// Add energy core and rings
		const core = document.createElement('div');
		core.className = 'core-glow';
		sphere.appendChild(core);

		// Add multiple orbital rings
		for (let i = 1; i <= 3; i++) {
			const ring = document.createElement('div');
			ring.className = `orbital-ring ring${i}`;
			sphere.appendChild(ring);
		}

		return dots;
	}

	function initializeGame() {
		const startScreen = document.querySelector('.start-screen') as HTMLDivElement;
		const sphere = document.querySelector('.sphere') as HTMLDivElement;
		
		if (startScreen && sphere) {
			createGalaxyBackground(startScreen);
			createSpherePoints(sphere);
			createEnergyField(sphere);
			
			sphereAnimation = sphere.animate([
				{ transform: 'rotateX(23.5deg) rotateY(0deg) scale(1)' },
				{ transform: 'rotateX(23.5deg) rotateY(180deg) scale(1.2)', offset: 0.5 },
				{ transform: 'rotateX(23.5deg) rotateY(360deg) scale(1)' }
			], {
				duration: 12000,
				iterations: Infinity,
				easing: 'ease-in-out'
			});
		}
	}

	onMount(initializeGame);

	onDestroy(() => {
		sphereAnimation?.cancel();
		particleAnimations.forEach(anim => anim.cancel());
	});
</script>

<!-- UI Components -->

<!-- Navigation Bar -->
<nav class="navbar glass-effect">
    <div class="nav-brand gradient-text">🎮 Memory Game</div>
    {#if userId}
        <div class="nav-user">
            <span class="username">Welcome, {username}!</span>
            <button class="nav-button" on:click={logout}>Logout</button>
        </div>
    {/if}
</nav>

<!-- Main Game Container -->
<main class="main-container">
    {#if !userId}
        <!-- Authentication UI -->
        <div class="auth-container">
            <div class="auth-card">
                <h1>Welcome to Memory Game</h1>
                <p class="subtitle">Login or register to start playing</p>
                <div class="form">
                    <input 
                        type="text" 
                        bind:value={username} 
                        placeholder="Username"
                        class="input-field"
                    />
                    <input 
                        type="password" 
                        bind:value={password} 
                        placeholder="Password"
                        class="input-field"
                    />
                    {#if loginError}
                        <p class="error">{loginError}</p>
                    {/if}
                    <div class="auth-buttons">
                        <button class="auth-button primary" on:click={login}>Login</button>
                        <button class="auth-button secondary" on:click={register}>Register</button>
                    </div>
                </div>
            </div>
        </div>
    {:else}
        <!-- Game UI -->
        <div class="game-container">
            <div class="game-area">
                {#if state === 'start'}
                    <div class="start-screen glass-effect">
                        <div class="galaxy-background">
                            <!-- Stars will be added here dynamically -->
                            <div class="star"></div>
                        </div>
                        <div class="game-sphere">
                            <div class="orbital-ring"></div>
                            <div class="orbital-ring ring2"></div>
                            <div class="orbital-ring ring3"></div>
                            <div class="sphere">
                                <!-- Energy particles will be added here dynamically -->
                                <div class="energy-particle"></div>
                                <div class="core-glow"></div>
                                <div class="dot"></div>
                            </div>
                        </div>
                        <h1 class="game-title gradient-text">
                            <span>M</span><span>E</span><span>M</span><span>O</span><span>R</span><span>Y</span>
                            <span> </span>
                            <span>G</span><span>A</span><span>M</span><span>E</span>
                        </h1>
                        <button class="start-button glass-effect" on:click={() => state = 'playing'}>
                            Start Game
                        </button>
                    </div>
                {/if}

                {#if state === 'paused'}
                    <div class="game-header">
                        <h1>Game Paused</h1>
                        <div class="menu-buttons">
                            <button class="game-button primary" on:click={() => state = 'playing'}>Resume</button>
                            <button class="game-button secondary" on:click={quitGame}>Quit</button>
                        </div>
                    </div>
                {/if}

                {#if state === 'playing'}
                    <div class="game-header">
                        <h1 class="timer" class:pulse={time <= 10}>
                            {time}
                        </h1>
                    </div>

                    <div class="matches">
                        {#each matches as card}
                            <div>{card}</div>
                        {/each}
                    </div>

                    <div class="cards">
                        {#each grid as card, cardIndex}
                            {@const isSelected = selected.includes(cardIndex)}
                            {@const isSelectedOrMatch = selected.includes(cardIndex) || matches.includes(card)}
                            {@const match = matches.includes(card)}

                            <button
                                class="card"
                                class:selected={isSelected}
                                class:flip={isSelectedOrMatch}
                                disabled={isSelectedOrMatch}
                                on:click={() => selectCard(cardIndex)}
                            >
                                <div class="back" class:match>
                                    {card}
                                </div>
                            </button>
                        {/each}
                    </div>

                    <div class="game-footer">
                        <button class="game-button secondary" on:click={quitGame}>Quit Game</button>
                    </div>
                {/if}

                {#if state === 'lost' || state === 'won'}
                    <div class="game-header">
                        <h1>{state === 'won' ? 'You Win! 🎉' : 'Game Over! 💩'}</h1>
                        <div class="menu-buttons">
                            <button class="game-button primary" on:click={() => (state = 'playing')}>
                                Play Again
                            </button>
                            <button class="game-button secondary" on:click={quitGame}>
                                Back to Menu
                            </button>
                        </div>
                    </div>
                {/if}
            </div>

            <div class="leaderboard-sidebar glass-effect">
                <div class="leaderboard-header">
                    <h2 class="gradient-text">Leaderboard</h2>
                </div>
                <div class="leaderboard-content">
                    <table>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Player</th>
                                <th>Score</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            {#each leaderboard as {rank, player, score, time_left}, i}
                                <tr class={player === username ? 'current-player' : ''}>
                                    <td>{rank}</td>
                                    <td>{player}</td>
                                    <td>{score}</td>
                                    <td>{time_left}s</td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    {/if}
</main>

<!-- Add svelte:window element to listen for keyboard events -->
<svelte:window on:keydown={pauseGame}/>

<!-- Styles -->
<style>
    /**
     * Navigation Styles
     * Defines appearance of top navigation bar
     */
    .navbar {
        background: var(--bg-2);
        padding: 0.4rem 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: var(--shadow-1);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        border-bottom: 1px solid var(--accent-1);
        height: 2.5rem; /* Fixed height */
    }

    .nav-brand {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--txt-1);
        letter-spacing: -0.02em;
    }

    .nav-user {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .username {
        color: var(--txt-1);
        opacity: 0.8;
    }

    .nav-button {
        padding: 0.6rem 1.2rem;
        border: 1px solid var(--accent-1);
        border-radius: var(--radius-2);
        background: transparent;
        color: var(--txt-1);
        cursor: pointer;
        transition: all 0.2s ease;
        font-weight: 500;
    }

    .nav-button:hover {
        background: var(--accent-1);
        transform: translateY(-1px);
        border-color: var(--border);
    }

    /**
     * Main Container Styles
     * Layout for game content area
     */
    .main-container {
        margin: 3rem auto 0;
        padding: 0;
        width: 100%;
        max-width: 1300px; /* Match game container */
    }

    /**
     * Authentication Styles
     * Login/Register form appearance
     */
    .auth-container {
        display: grid;
        place-items: center;
        min-height: calc(100vh - 7rem);
    }

    .auth-card {
        background: var(--bg-2);
        padding: 2.5rem;
        border-radius: var(--radius-1);
        box-shadow: var(--shadow-2);
        width: 100%;
        max-width: 400px;
        border: 1px solid var(--accent-1);
    }

    .subtitle {
        text-align: center;
        color: var(--txt-1);
        opacity: 0.8;
        margin: 1rem 0;
    }

    .input-field {
        width: 100%;
        padding: 0.8rem 1rem;
        font-size: 1rem;
        border: 1px solid var(--accent-1);
        border-radius: var(--radius-2);
        background: var(--bg-1);
        color: var(--txt-1);
        transition: all 0.2s ease;
    }

    .input-field:focus {
        outline: none;
        border-color: var(--border);
        box-shadow: 0 0 0 2px var(--accent-1);
    }

    /**
     * Game Container Styles
     * Layout for game area and leaderboard
     */
    .game-container {
        display: grid;
        grid-template-columns: minmax(auto, 800px) 450px; /* Wider game area */
        gap: 1rem;
        max-width: 1300px; /* Increased max-width */
        margin: 0 auto;
        padding: 0 1rem; /* Add padding on both sides */

        @media (max-width: 1366px) {
            grid-template-columns: minmax(auto, 780px) 450px;
            gap: 0.75rem;
            padding: 0 0.75rem;
        }
    }

    /**
     * Game Area
     */
    .game-area {
        padding: 0.75rem;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    /**
     * Leaderboard
     */
    .leaderboard-sidebar {
        padding: 0.75rem 1rem;
        width: 100%;
        min-width: 450px;
    }

    .leaderboard-header {
        margin-bottom: 0.75rem;
    }

    .leaderboard-header h2 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }

    .leaderboard-content {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    table {
        width: 100%;
        font-size: 0.9rem;
        border-collapse: separate;
        border-spacing: 0;
    }

    th, td {
        padding: 0.5rem 0.75rem;
        white-space: nowrap;
    }

    th {
        font-weight: 600;
        padding: 0.5rem 0.75rem;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.05em;
    }

    td {
        padding: 0.35rem;
        text-align: left;
        border-bottom: 1px solid var(--accent-1);
    }

    tr:last-child td {
        border-bottom: none;
    }

    tr:hover:not(.highlight) {
        background: var(--accent-2);
    }

    /**
     * Buttons
     */
    .game-button, .auth-button {
        padding: 0.35rem 0.7rem;
        font-size: 0.85rem;
        border-radius: var(--radius-2);
        cursor: pointer;
        transition: all 0.2s ease;
        font-weight: 500;
        letter-spacing: 0.01em;
    }

    .primary {
        background: linear-gradient(45deg, var(--border), hsl(200 100% 60%));
        border: none;
        box-shadow: var(--shadow-1);
    }

    .primary:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-2);
        opacity: 0.9;
    }

    .secondary {
        background: transparent;
        color: var(--txt-1);
    }

    .secondary:hover {
        background: var(--border);
        color: var(--bg-1);
    }

    /**
     * Card Styles
     * Appearance and animations for game cards
     */
    .cards {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 0.5rem;
        padding: 0.5rem;
        width: 100%;
        max-width: 800px; /* Match container width */
    }

    .card {
        aspect-ratio: 1;
        width: 100%;
        max-width: 110px; /* Larger cards */
        height: auto;
        margin: 0 auto;
        font-size: clamp(1.5rem, 2vw, 2rem);
        background-color: var(--bg-1);
        border: 1px solid var(--accent-1);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        transform-style: preserve-3d;
        border-radius: var(--radius-2);
        box-shadow: var(--shadow-1);

        @media (max-width: 1366px) {
            max-width: 105px;
        }

        &.selected {
            border: 2px solid var(--border); /* Thinner border for mobile */
        }

        &.flip {
            rotate: y 180deg;
            pointer-events: none;
        }

        & .back {
            position: absolute;
            inset: 0;
            display: grid;
            place-content: center;
            backface-visibility: hidden;
            rotate: y 180deg;
            background-color: var(--bg-2);
        }

        & .match {
            transition: opacity 0.3s ease-out;
            opacity: 0.4;
        }

        &:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-2);
            border-color: var(--border);
        }
    }

    /**
     * Timer Styles
     * Countdown timer appearance and animations
     */
    .timer {
        transition: color 0.3s ease;
        font-size: clamp(1.75rem, 2.5vw, 2.25rem);
        font-weight: 700;
        letter-spacing: -0.03em;
    }

    .pulse {
        color: var(--pulse);
        animation: pulse 1s infinite ease;
        text-shadow: 0 0 15px var(--pulse);
    }

    @keyframes pulse {
        to {
            scale: 1.4;
        }
    }

    .game-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        margin-bottom: 0.35rem;
    }

    /**
     * Add minimum widths for columns
     */
    th:nth-child(1), td:nth-child(1) { /* Rank column */
        min-width: 80px;
    }

    th:nth-child(2), td:nth-child(2) { /* Player column */
        min-width: 150px;
    }

    th:nth-child(3), td:nth-child(3) { /* Score column */
        min-width: 100px;
    }

    th:nth-child(4), td:nth-child(4) { /* Time column */
        min-width: 90px;
    }

    /**
     * Column widths
     */
    th:nth-child(1), td:nth-child(1) { /* Rank */
        width: 80px;
        text-align: center;
    }

    th:nth-child(2), td:nth-child(2) { /* Player */
        width: 150px;
        text-align: left;
    }

    th:nth-child(3), td:nth-child(3) { /* Score */
        width: 100px;
        text-align: center;
    }

    th:nth-child(4), td:nth-child(4) { /* Time */
        width: 90px;
        text-align: center;
    }

    .game-container {
        width: 100vw;
        height: 100vh;
        display: grid;
        place-items: center;
        background: var(--bg-1);
        perspective: 1000px;
    }

    .start-screen {
        position: relative;
        overflow: hidden;
        min-height: min(600px, 90vh);
        padding: 1rem;
    }

    .star {
        position: absolute;
        width: 2px;
        height: 2px;
        background: white;
        border-radius: 50%;
        animation: twinkle 3s infinite ease-in-out;
    }

    .energy-particle {
        position: absolute;
        width: 4px;
        height: 4px;
        background: var(--border);
        border-radius: 50%;
        filter: blur(1px);
    }

    .sphere {
        position: relative;
        width: min(200px, 50vw);
        height: min(200px, 50vw);
        transform-style: preserve-3d;
        perspective: 1000px;
    }

    .dot {
        position: absolute;
        width: 3px;
        height: 3px;
        background: var(--border);
        border-radius: 50%;
        transform-style: preserve-3d;
        box-shadow: 
            0 0 8px var(--border),
            0 0 15px var(--border);
    }

    .core-glow {
        position: absolute;
        top: 50%;
        left: 50%;
        width: 30px;
        height: 30px;
        background: var(--border);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        box-shadow: 
            0 0 30px var(--border),
            0 0 60px var(--border),
            0 0 90px var(--border),
            0 0 120px var(--border);
        animation: pulse 2s ease-in-out infinite;
    }

    .orbital-ring {
        position: absolute;
        top: 50%;
        left: 50%;
        width: min(180px, 45vw);
        height: min(180px, 45vw);
        border: 2px solid var(--border);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        animation: rotate 8s linear infinite;
        box-shadow: 0 0 20px var(--border);
    }

    .ring2 {
        width: min(140px, 35vw);
        height: min(140px, 35vw);
        animation-duration: 6s;
        animation-direction: reverse;
        border-color: var(--accent-1);
    }

    .ring3 {
        width: min(100px, 25vw);
        height: min(100px, 25vw);
        animation-duration: 4s;
    }

    @keyframes rotate {
        from { transform: translate(-50%, -50%) rotate(0deg); }
        to { transform: translate(-50%, -50%) rotate(360deg); }
    }

    @keyframes pulse {
        0%, 100% { 
            transform: translate(-50%, -50%) scale(1);
            opacity: 0.8;
        }
        50% { 
            transform: translate(-50%, -50%) scale(1.8);
            opacity: 0.4;
        }
    }

    @keyframes twinkle {
        0%, 100% { 
            opacity: 0.2;
            transform: scale(0.8);
        }
        50% { 
            opacity: 1;
            transform: scale(1.2);
        }
    }

    /* Enhanced title animation */
    .game-title {
        font-size: clamp(2rem, 5vw, 3rem);
        font-weight: bold;
        text-shadow: 
            0 0 10px var(--border),
            0 0 20px var(--border),
            0 0 30px var(--border);
    }

    .game-title span {
        display: inline-block;
        animation: float 2s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { 
            transform: translateY(0) scale(1);
            text-shadow: 0 0 10px var(--border);
        }
        50% { 
            transform: translateY(-15px) scale(1.1);
            text-shadow: 
                0 0 20px var(--border),
                0 0 30px var(--border);
        }
    }

    /* Enhanced title letter animations */
    .game-title span:nth-child(1) { animation-delay: 0.0s; }
    .game-title span:nth-child(2) { animation-delay: 0.1s; }
    .game-title span:nth-child(3) { animation-delay: 0.2s; }
    .game-title span:nth-child(4) { animation-delay: 0.3s; }
    .game-title span:nth-child(5) { animation-delay: 0.4s; }
    .game-title span:nth-child(6) { animation-delay: 0.5s; }
    .game-title span:nth-child(8) { animation-delay: 0.6s; }
    .game-title span:nth-child(9) { animation-delay: 0.7s; }
    .game-title span:nth-child(10) { animation-delay: 0.8s; }
    .game-title span:nth-child(11) { animation-delay: 0.9s; }

    .glass-effect {
        backdrop-filter: blur(8px);
        background: hsl(220 20% 12% / 0.8);
        border: 1px solid var(--border);
        box-shadow: 0 0 20px var(--accent-1);
    }

    .gradient-text {
        background: linear-gradient(90deg, var(--border), hsl(200 100% 60%));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }

    /* Responsive navbar */
    .navbar {
        padding: 0.4rem 1rem;
        
        @media (max-width: 768px) {
            padding: 0.4rem 0.5rem;
        }
    }

    /* Responsive typography */
    h1 {
        font-size: clamp(2rem, 5vw, 4rem);
    }

    /* Adjust auth container for mobile */
    .auth-container {
        padding: 1rem;
    }

    .auth-card {
        width: 100%;
        max-width: min(400px, 90vw);
        padding: 1.5rem;
    }

    /* Responsive game header */
    .game-header {
        flex-direction: column;
        gap: 1rem;
        padding: 1rem;

        @media (min-width: 768px) {
            flex-direction: row;
        }
    }

    /* Adjust sphere animation container for mobile */
    .start-screen {
        min-height: min(600px, 90vh);
        padding: 1rem;
    }

    .sphere {
        width: min(200px, 50vw);
        height: min(200px, 50vw);
    }

    /* Adjust orbital rings for smaller sphere */
    .orbital-ring {
        width: min(180px, 45vw);
        height: min(180px, 45vw);
    }

    .ring2 {
        width: min(140px, 35vw);
        height: min(140px, 35vw);
    }

    .ring3 {
        width: min(100px, 25vw);
        height: min(100px, 25vw);
    }

    /* Make buttons more touch-friendly on mobile */
    .game-button, 
    .auth-button,
    .start-button {
        padding: clamp(0.5rem, 2vw, 1.5rem);
        font-size: clamp(0.875rem, 2vw, 1rem);
        min-height: 44px; /* Minimum touch target size */
        min-width: 44px;
    }

    /* Adjust form inputs for better mobile experience */
    .input-field {
        padding: 0.8rem;
        font-size: 16px; /* Prevent zoom on iOS */
        min-height: 44px;
    }

    /* Add some breathing room for mobile */
    .main-container {
        margin: 3.5rem auto 0;
        padding: 0 0.5rem;

        @media (max-width: 768px) {
            margin-top: 3rem;
            padding: 0 0.25rem;
        }
    }

    /* Ensure table cells remain readable */
    td, th {
        padding: 0.5rem;
        font-size: clamp(0.75rem, 2vw, 1rem);
        white-space: nowrap;
    }

    /* Add smooth transitions for responsive changes */
    * {
        transition: all 0.3s ease-in-out;
    }

    .game-footer {
        margin-top: 1rem;
        text-align: center;
    }

    .game-header {
        text-align: center;
        margin-bottom: 1rem;
    }
</style>
