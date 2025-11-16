// Wallpaper selection and management
const STORAGE_KEY = 'user_wallpaper';

/**
 * Get the current season based on month
 */
function getCurrentSeason() {
  const month = new Date().getMonth(); // 0 = January, 11 = December

  if (month >= 2 && month <= 4) {
    return 'spring'; // March, April, May
  } else if (month >= 5 && month <= 7) {
    return 'summer'; // June, July, August
  } else if (month >= 8 && month <= 10) {
    return 'autumn'; // September, October, November
  } else {
    return 'winter'; // December, January, February
  }
}

/**
 * Apply wallpaper to the app body element
 * @param {string} wallpaper - Wallpaper name ('auto', 'spring', 'summer', 'autumn', 'winter', 'winter_01', 'abstract_01', etc.)
 */
export function applyWallpaper(wallpaper) {
  const body = document.querySelector('.ios-app');
  if (!body) {
    console.warn('[wallpaper] .ios-app element not found');
    return;
  }

  // Remove all wallpaper and season classes
  body.classList.remove(
    'season-spring', 'season-summer', 'season-autumn', 'season-winter',
    'wallpaper-spring', 'wallpaper-summer', 'wallpaper-autumn', 'wallpaper-winter',
    'wallpaper-winter_01', 'wallpaper-abstract_01', 'wallpaper-abstract_02', 'wallpaper-abstract_03'
  );

  // Apply wallpaper
  if (wallpaper === 'auto') {
    // Use seasonal wallpaper
    const season = getCurrentSeason();
    body.classList.add(`season-${season}`);
    console.log('[wallpaper] Applied auto (seasonal):', season);
  } else {
    // Use user-selected wallpaper
    body.classList.add(`wallpaper-${wallpaper}`);
    console.log('[wallpaper] Applied user wallpaper:', wallpaper);
  }
}

/**
 * Get the stored wallpaper preference from localStorage
 * @returns {string} Wallpaper name or 'auto' if not set
 */
export function getStoredWallpaper() {
  return localStorage.getItem(STORAGE_KEY) || 'auto';
}

/**
 * Save wallpaper preference to localStorage
 * @param {string} wallpaper - Wallpaper name to save
 */
export function saveWallpaper(wallpaper) {
  localStorage.setItem(STORAGE_KEY, wallpaper);
  console.log('[wallpaper] Saved preference:', wallpaper);
}

/**
 * Initialize wallpaper selector on settings page
 */
export function initWallpaperSelector() {
  const grid = document.querySelector('#wallpaperGrid');
  if (!grid) {
    console.log('[wallpaper] Wallpaper grid not found (not on settings page)');
    return;
  }

  const currentWallpaper = getStoredWallpaper();
  console.log('[wallpaper] Current wallpaper:', currentWallpaper);

  // Set active state on current selection
  const options = grid.querySelectorAll('.wallpaper-option');
  options.forEach(option => {
    const wallpaperName = option.dataset.wallpaper;
    if (wallpaperName === currentWallpaper) {
      option.classList.add('active');
    }

    // Add click handler
    option.addEventListener('click', () => {
      // Remove active from all
      options.forEach(opt => opt.classList.remove('active'));

      // Add active to clicked
      option.classList.add('active');

      // Save and apply
      saveWallpaper(wallpaperName);
      applyWallpaper(wallpaperName);
    });
  });

  console.log('[wallpaper] Wallpaper selector initialized');
}
